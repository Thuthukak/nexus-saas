<?php

declare(strict_types=1);

namespace Modules\Core\Email;

/**
 * Pure: decides the From name/address and Reply-To for one email.
 *
 * Tenant-owned provider (Microsoft 365 / own SMTP): From is the sending user's OWN address when it is on the
 * same domain as the configured sender (the tenant's relay can authenticate that mailbox); otherwise the
 * configured sender, with the user as Reply-To.
 *
 * Platform shared domain: the platform cannot sign for a user's personal address (SPF/DKIM/DMARC would fail and
 * mail would be rejected as spoofed), so From is the platform's verified address with the user's display name,
 * and replies go to the signed Reply-To (or the user's address when no thread is expected).
 */
final class SenderIdentity
{
    /** @return array{address:string, name:?string, reply_to:?string} */
    public static function resolve(
        bool $tenantProvider,
        ?string $configuredFrom,
        string $platformFrom,
        string $tenantName,
        ?string $userName,
        ?string $userEmail,
        ?string $replyAddress,
    ): array {
        $userName = self::cleanName($userName);
        $tenantName = self::cleanName($tenantName) ?? 'Workspace';

        if ($tenantProvider && $configuredFrom !== null) {
            if ($userEmail !== null && self::domain($userEmail) === self::domain($configuredFrom)) {
                return ['address' => $userEmail, 'name' => $userName, 'reply_to' => null];
            }

            return ['address' => $configuredFrom, 'name' => $userName ?? $tenantName, 'reply_to' => $userEmail];
        }

        return [
            'address' => $platformFrom,
            'name' => $userName !== null ? "{$userName} via {$tenantName}" : $tenantName,
            'reply_to' => $replyAddress ?? $userEmail,
        ];
    }

    public static function domain(string $address): string
    {
        return strtolower(substr(strrchr($address, '@') ?: '@', 1));
    }

    private static function cleanName(?string $name): ?string
    {
        $name = trim((string) preg_replace('/[\x00-\x1f\x7f"<>]/', '', (string) $name));

        return $name === '' ? null : mb_substr($name, 0, 100);
    }
}
