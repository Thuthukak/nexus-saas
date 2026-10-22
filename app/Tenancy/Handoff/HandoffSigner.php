<?php

declare(strict_types=1);

namespace App\Tenancy\Handoff;

/**
 * HMAC-SHA256 over (tenant, user, nonce, expiry), in the style of Laravel signed routes.
 * Pure: takes a key string, no framework needed. The signature binds the token to one
 * tenant and one user and fixes its expiry, so none of them can be altered in the URL.
 */
final class HandoffSigner
{
    public function __construct(#[\SensitiveParameter] private readonly string $key)
    {
    }

    /** Derives a dedicated signing key from the application key (domain separation). */
    public static function fromAppKey(#[\SensitiveParameter] string $appKey): self
    {
        return new self(hash('sha256', 'login-handoff:v1|'.$appKey));
    }

    public function sign(string $tenantId, int $userId, string $nonce, int $expiresAt): string
    {
        return hash_hmac('sha256', self::payload($tenantId, $userId, $nonce, $expiresAt), $this->key);
    }

    public function verify(string $signature, string $tenantId, int $userId, string $nonce, int $expiresAt): bool
    {
        // Reject anything that could smuggle the delimiter into the signed payload.
        foreach ([$tenantId, $nonce] as $part) {
            if (str_contains($part, '|')) {
                return false;
            }
        }

        return hash_equals($this->sign($tenantId, $userId, $nonce, $expiresAt), $signature);
    }

    private static function payload(string $tenantId, int $userId, string $nonce, int $expiresAt): string
    {
        return implode('|', [$tenantId, $userId, $nonce, $expiresAt]);
    }
}
