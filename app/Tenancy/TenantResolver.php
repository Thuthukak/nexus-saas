<?php

declare(strict_types=1);

namespace App\Tenancy;

final class TenantResolver
{
    public function isControlPlaneHost(string $host): bool
    {
        return strtolower($host) === strtolower(config('tenancy.control_plane_host'));
    }

    /**
     * Resolve a tenant from a hostname: wildcard subdomain, or a verified and
     * active mapped custom domain. Anything else resolves to null.
     */
    public function resolve(string $host): ?Tenant
    {
        $host = strtolower($host);
        $root = strtolower(config('tenancy.root_domain'));

        if (str_ends_with($host, '.'.$root)) {
            $sub = substr($host, 0, -strlen('.'.$root));

            // Single-label subdomains only; reserved names never map to a tenant.
            if ($sub === '' || str_contains($sub, '.') || in_array($sub, config('tenancy.reserved_subdomains'), true)) {
                return null;
            }

            return Tenant::query()->where('subdomain', $sub)->first();
        }

        $domain = TenantDomain::query()->resolvable()->where('domain', $host)->with('tenant')->first();

        return $domain?->tenant;
    }

    /** Used by Caddy's on-demand TLS `ask` endpoint. */
    public function isCertifiableHost(string $host): bool
    {
        $host = strtolower($host);

        return TenantDomain::query()
            ->resolvable()
            ->where('domain', $host)
            ->whereHas('tenant', fn ($q) => $q->where('status', Tenant::ACTIVE))
            ->exists();
    }

    public static function normaliseSubdomain(string $value, string $fallbackFrom = ''): string
    {
        $slug = static fn (string $v): string => trim(preg_replace('/[^a-z0-9-]+/', '-', strtolower($v)) ?? '', '-');

        $result = $slug($value);

        return $result !== '' ? $result : $slug($fallbackFrom);
    }

    public static function isReserved(string $subdomain): bool
    {
        return in_array($subdomain, config('tenancy.reserved_subdomains'), true);
    }
}
