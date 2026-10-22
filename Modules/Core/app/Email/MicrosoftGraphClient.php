<?php

declare(strict_types=1);

namespace Modules\Core\Email;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Mime\Email;

/**
 * Microsoft Graph, application permissions (Mail.Send, Mail.Read granted by the tenant's IT administrator through
 * the Admin Consent flow). Thin wrapper over Http so it can be faked in tests.
 */
final class MicrosoftGraphClient
{
    public function configured(): bool
    {
        return filled(config('services.microsoft_graph.client_id')) && filled(config('services.microsoft_graph.client_secret'));
    }

    /** Fixed redirect URI on the platform root domain (Azure cannot register one per tenant subdomain). */
    public function redirectUri(): string
    {
        $port = config('tenancy.port');

        return config('tenancy.scheme', 'https').'://'.config('tenancy.root_domain').($port ? ':'.$port : '').'/integrations/microsoft/callback';
    }

    public function consentUrl(string $state): string
    {
        return 'https://login.microsoftonline.com/organizations/v2.0/adminconsent?'.http_build_query([
            'client_id' => config('services.microsoft_graph.client_id'),
            'scope' => 'https://graph.microsoft.com/.default',
            'redirect_uri' => $this->redirectUri(),
            'state' => $state,
        ]);
    }

    public static function isGuid(string $v): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) === 1;
    }

    /** @return array{access_token:string, expires_at:int} */
    public function token(string $msTenantId): array
    {
        if (! self::isGuid($msTenantId)) {
            throw new RuntimeException('Invalid Microsoft tenant id.');
        }
        $res = Http::asForm()->timeout(15)->post("https://login.microsoftonline.com/{$msTenantId}/oauth2/v2.0/token", [
            'client_id' => config('services.microsoft_graph.client_id'),
            'client_secret' => config('services.microsoft_graph.client_secret'),
            'scope' => 'https://graph.microsoft.com/.default',
            'grant_type' => 'client_credentials',
        ]);
        if (! $res->successful() || ! is_string($res->json('access_token'))) {
            throw new RuntimeException('Microsoft did not issue an access token (HTTP '.$res->status().').');
        }

        return ['access_token' => $res->json('access_token'), 'expires_at' => time() + (int) $res->json('expires_in', 3000)];
    }

    /** Does this mailbox exist in the consenting Microsoft tenant? Proves the tenant controls the sender address. */
    public function mailboxExists(string $accessToken, string $address): bool
    {
        $res = Http::withToken($accessToken)->timeout(15)->get('https://graph.microsoft.com/v1.0/users/'.rawurlencode($address), ['$select' => 'id']);

        return $res->successful();
    }

    /** Current token for this tenant's settings, refreshed (and re-stored, encrypted) when it is about to expire. */
    public function accessToken(EmailSettings $settings): string
    {
        $c = (array) $settings->credentials;
        if (isset($c['access_token'], $c['expires_at']) && $c['expires_at'] > time() + 60) {
            return (string) $c['access_token'];
        }
        $fresh = $this->token((string) ($c['ms_tenant_id'] ?? ''));
        $settings->credentials = ['ms_tenant_id' => $c['ms_tenant_id']] + $fresh;
        $settings->save();

        return $fresh['access_token'];
    }

    public function sendMail(string $accessToken, Email $email): void
    {
        $from = $email->getFrom()[0];
        $addr = fn ($a) => ['emailAddress' => array_filter(['address' => $a->getAddress(), 'name' => $a->getName() ?: null])];
        $html = $email->getHtmlBody();

        $res = Http::withToken($accessToken)->timeout(20)->post('https://graph.microsoft.com/v1.0/users/'.rawurlencode($from->getAddress()).'/sendMail', [
            'message' => array_filter([
                'subject' => $email->getSubject(),
                'body' => ['contentType' => $html !== null ? 'HTML' : 'Text', 'content' => $html ?? $email->getTextBody()],
                'from' => $addr($from),
                'toRecipients' => array_map($addr, $email->getTo()),
                'replyTo' => array_map($addr, $email->getReplyTo()),
            ]),
            'saveToSentItems' => true,
        ]);
        if ($res->status() !== 202) {
            throw new RuntimeException('Microsoft Graph refused the message (HTTP '.$res->status().').');
        }
    }
}
