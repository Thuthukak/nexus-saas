<?php

declare(strict_types=1);

namespace Modules\Core\Email;

use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantRunner;
use Illuminate\Support\Str;
use Throwable;

/** CoreEmailIntegration: reads and changes how the CURRENT tenant sends email. */
final class EmailIntegrationService
{
    public const CONSENT_TTL = 900;

    public function __construct(
        private readonly TenantContext $context,
        private readonly EmailPlanPolicy $plans,
        private readonly SmtpConnectionTester $smtp,
        private readonly MicrosoftGraphClient $graph,
        private readonly TenantRunner $runner,
    ) {
    }

    public function settings(): ?EmailSettings
    {
        return EmailSettings::query()->first();
    }

    /** The provider mail actually goes through: the configured one only while ACTIVE and still allowed by the plan, otherwise the platform's. */
    public function effectiveProvider(?EmailSettings $s): EmailProvider
    {
        if ($s === null || ! $s->isActive()) {
            return EmailProvider::PlatformShared;
        }
        $provider = EmailProvider::from($s->provider);

        return $this->plans->allows($this->context->requireId(), $provider) ? $provider : EmailProvider::PlatformShared;
    }

    /** @return array{email_provider:string, from_address:?string, status:string, consent_url?:string, last_error:?string, effective_provider:string} */
    public function payload(?string $consentUrl = null): array
    {
        $s = $this->settings();
        $out = [
            'email_provider' => $s?->provider ?? EmailProvider::PlatformShared->value,
            'from_address' => $s?->from_address ?? config('mail.from.address'),
            'status' => $s?->status ?? 'active',
            'last_error' => $s?->last_error,
            'effective_provider' => $this->effectiveProvider($s)->value,
        ];
        if ($consentUrl !== null) {
            $out['consent_url'] = $consentUrl;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $input  email_provider, from_address, smtp_host/port/username/password
     * @return array<string, mixed> the EmailIntegrationPayload (plus consent_url while Microsoft consent is pending)
     *
     * @throws EmailProviderNotPermitted|EmailConfigurationInvalid
     */
    public function configure(int $userId, array $input): array
    {
        $tenantId = $this->context->requireId();
        $requested = $input['email_provider'] ?? null;
        $provider = is_string($requested) ? EmailProvider::tryFrom($requested) : null;
        if ($provider === null) {
            throw new EmailConfigurationInvalid(['email_provider' => ['Choose platform_shared, microsoft365 or custom_smtp.']]);
        }
        if (! $this->plans->allows($tenantId, $provider)) {
            throw new EmailProviderNotPermitted();
        }

        $from = null;
        if ($provider->needsEnterprise()) {
            $from = is_string($input['from_address'] ?? null) ? trim($input['from_address']) : '';
            if (filter_var($from, FILTER_VALIDATE_EMAIL) === false || strlen($from) > 254 || preg_match('/[\r\n]/', $from)) {
                throw new EmailConfigurationInvalid(['from_address' => ['A valid sender address is required.']]);
            }
            $from = strtolower($from);
        }

        return match ($provider) {
            EmailProvider::PlatformShared => $this->useShared(),
            EmailProvider::CustomSmtp => $this->useSmtp($from, $input),
            EmailProvider::Microsoft365 => $this->startMicrosoft($userId, $tenantId, $from),
        };
    }

    private function useShared(): array
    {
        if ($row = $this->settings()) {
            $row->delete();   // no row == platform_shared; nothing sensitive is kept
        }

        return $this->payload();
    }

    private function useSmtp(string $from, array $input): array
    {
        $errors = SmtpSettings::errors($input);
        if ($errors !== []) {
            throw new EmailConfigurationInvalid($errors);
        }
        $smtp = SmtpSettings::fromInput($input);

        try {
            $this->smtp->test($smtp);   // must succeed BEFORE anything is saved or marked active
        } catch (SmtpConnectionFailed $e) {
            throw new EmailConfigurationInvalid(['smtp_host' => [$e->getMessage()]]);
        }

        $row = $this->settings() ?? new EmailSettings();
        $row->fill([
            'provider' => EmailProvider::CustomSmtp->value, 'from_address' => $from, 'status' => 'active', 'credentials' => $smtp->toStored(),
            'consent_nonce_hash' => null, 'consent_expires_at' => null, 'activated_at' => now(), 'last_error' => null, 'last_error_at' => null,
        ])->save();

        return $this->payload();
    }

    private function startMicrosoft(int $userId, string $tenantId, string $from): array
    {
        if (! $this->graph->configured()) {
            throw new EmailConfigurationInvalid(['email_provider' => ['Microsoft 365 is not set up on this platform yet.']]);
        }
        $nonce = Str::random(40);
        $state = SignedPayload::fromAppKey((string) config('app.key'), 'm365-consent')->encode(['t' => $tenantId, 'u' => $userId, 'n' => $nonce], time() + self::CONSENT_TTL);

        // Pending until consent completes; mail keeps using the platform domain meanwhile.
        $row = $this->settings() ?? new EmailSettings();
        $row->fill([
            'provider' => EmailProvider::Microsoft365->value, 'from_address' => $from, 'status' => 'pending', 'credentials' => null,
            'consent_nonce_hash' => hash('sha256', $nonce), 'consent_expires_at' => now()->addSeconds(self::CONSENT_TTL),
            'activated_at' => null, 'last_error' => null, 'last_error_at' => null,
        ])->save();

        return $this->payload($this->graph->consentUrl($state));
    }

    /**
     * Microsoft redirects the IT administrator here (platform root domain, no session). Everything is decided from
     * the signed, single-use, expiring state; the browser's cookies play no part.
     *
     * @param  array<string, mixed>  $query
     * @return array{outcome:'ok'|'denied'|'failed'|'invalid', subdomain:?string}
     */
    public function completeMicrosoftConsent(array $query): array
    {
        $data = SignedPayload::fromAppKey((string) config('app.key'), 'm365-consent')->decode((string) ($query['state'] ?? ''));
        $tenant = is_array($data) && is_string($data['t'] ?? null) ? Tenant::query()->find($data['t']) : null;
        if ($data === null || $tenant === null) {
            return ['outcome' => 'invalid', 'subdomain' => null];
        }

        $outcome = $this->runner->run($tenant->id, function () use ($data, $query): string {
            $row = $this->settings();
            if ($row === null || $row->provider !== 'microsoft365' || $row->status !== 'pending'
                || $row->consent_nonce_hash === null || ! hash_equals($row->consent_nonce_hash, hash('sha256', (string) $data['n']))
                || $row->consent_expires_at === null || $row->consent_expires_at->isPast()) {
                return 'invalid';
            }
            $row->consent_nonce_hash = null;   // single use, whatever happens next
            $row->consent_expires_at = null;

            $msTenant = (string) ($query['tenant'] ?? '');
            if (isset($query['error']) || strtolower((string) ($query['admin_consent'] ?? '')) !== 'true' || ! MicrosoftGraphClient::isGuid($msTenant)) {
                return $this->fail($row, 'Admin consent was not granted.', 'denied');
            }
            if (! $this->plans->allows($this->context->requireId(), EmailProvider::Microsoft365)) {
                return $this->fail($row, 'The plan no longer includes Microsoft 365 delivery.', 'failed');
            }

            try {
                $token = $this->graph->token($msTenant);
                if (! $this->graph->mailboxExists($token['access_token'], (string) $row->from_address)) {
                    return $this->fail($row, 'The sender address is not a mailbox in the Microsoft 365 tenant that granted consent.', 'failed');
                }
            } catch (Throwable $e) {
                report($e);

                return $this->fail($row, 'Could not complete the Microsoft 365 connection.', 'failed');
            }

            $row->fill(['status' => 'active', 'credentials' => ['ms_tenant_id' => strtolower($msTenant)] + $token, 'activated_at' => now(), 'last_error' => null, 'last_error_at' => null])->save();

            return 'ok';
        });

        return ['outcome' => $outcome, 'subdomain' => $tenant->subdomain];
    }

    public function recordDeliveryFailure(string $message): void
    {
        $row = $this->settings();
        $row?->forceFill(['last_error' => mb_substr($message, 0, 300), 'last_error_at' => now()])->save();
    }

    private function fail(EmailSettings $row, string $why, string $outcome): string
    {
        $row->fill(['status' => 'failed', 'last_error' => $why, 'last_error_at' => now()])->save();

        return $outcome;
    }
}
