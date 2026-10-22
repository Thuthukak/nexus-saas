<?php

declare(strict_types=1);

namespace App\Tenancy\Handoff;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Redeems a login handoff on the tenant's own hostname (runs under TenantMiddleware, so the
 * tenant is already resolved from the hostname and the RLS context is set).
 *
 * Checks, in order: format, token's tenant == this host's tenant, signature, expiry, then an
 * atomic single-use claim bound to tenant + user. Every failure is the same generic 403.
 */
final class HandoffController
{
    private const FAIL = 'This sign-in link is invalid, has expired, or was already used.';

    public function __invoke(Request $request): RedirectResponse
    {
        $tenantId = (string) $request->query('t', '');
        $userId = (string) $request->query('u', '');
        $nonce = (string) $request->query('n', '');
        $expiresAt = (string) $request->query('e', '');
        $signature = (string) $request->query('s', '');

        abort_unless(
            ctype_digit($userId) && ctype_digit($expiresAt)
            && preg_match('/^[0-9a-f]{64}$/', $nonce) === 1
            && preg_match('/^[0-9a-f]{64}$/', $signature) === 1
            && preg_match('/^[0-9a-f-]{36}$/', $tenantId) === 1,
            403, self::FAIL,
        );

        // Bound to the tenant: a token issued for tenant A is useless on tenant B's hostname.
        $tenant = $request->attributes->get('tenant');
        abort_unless($tenant !== null && $tenant->id === $tenantId, 403, self::FAIL);

        $signer = HandoffSigner::fromAppKey((string) config('app.key'));
        abort_unless($signer->verify($signature, $tenantId, (int) $userId, $nonce, (int) $expiresAt), 403, self::FAIL);
        abort_unless((int) $expiresAt >= now()->getTimestamp(), 403, self::FAIL);

        // Atomic single use: exactly one concurrent request can flip redeemed_at from NULL.
        $claimed = DB::update(
            'update login_handoffs set redeemed_at = ?
             where nonce_hash = ? and tenant_id = ? and user_id = ? and redeemed_at is null and expires_at >= ?',
            [now(), hash('sha256', $nonce), $tenantId, (int) $userId, now()],
        );
        abort_unless($claimed === 1, 403, self::FAIL);

        $guard = Auth::guard('workspace');
        $user = $guard->getProvider()->retrieveById((int) $userId); // tenant-scoped by the global scope AND RLS
        abort_if($user === null || ($user->is_active ?? true) === false, 403, self::FAIL);

        $guard->login($user);
        $request->session()->regenerate(); // new session ID on login: no session fixation
        $user->forceFill(['last_login_at' => now()])->save();

        return redirect('/dashboard')->header('Referrer-Policy', 'no-referrer');
    }
}
