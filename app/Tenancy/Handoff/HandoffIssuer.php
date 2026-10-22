<?php

declare(strict_types=1);

namespace App\Tenancy\Handoff;

use App\Tenancy\TenantUrl;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues a single-use, one-minute login handoff URL on the TENANT host.
 * Only a SHA-256 hash of the nonce is stored, so a database leak yields no usable token.
 * Insert it inside the provisioning transaction: no tenant means no token.
 */
final class HandoffIssuer
{
    public function issue(string $tenantId, int $userId, string $subdomain): string
    {
        $nonce = bin2hex(random_bytes(32));
        $expiresAt = now()->addSeconds((int) config('tenancy.handoff_ttl_seconds', 60))->getTimestamp();

        DB::table('login_handoffs')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'nonce_hash' => hash('sha256', $nonce),
            'expires_at' => date('Y-m-d H:i:s', $expiresAt),
            'created_at' => now(),
        ]);

        $signer = HandoffSigner::fromAppKey((string) config('app.key'));

        return TenantUrl::workspace($subdomain, '/auth/handoff').'?'.http_build_query([
            't' => $tenantId,
            'u' => $userId,
            'n' => $nonce,
            'e' => $expiresAt,
            's' => $signer->sign($tenantId, $userId, $nonce, $expiresAt),
        ]);
    }
}
