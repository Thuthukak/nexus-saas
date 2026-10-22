<?php

declare(strict_types=1);

namespace App\Tenancy;

final class TenantUrl
{
    /** Pure builder, unit-testable without the framework. */
    public static function build(string $scheme, string $rootDomain, ?int $port, string $subdomain, string $path = '/'): string
    {
        $host = $subdomain.'.'.$rootDomain;

        return $scheme.'://'.$host.($port !== null ? ':'.$port : '').'/'.ltrim($path, '/');
    }

    public static function workspace(string $subdomain, string $path = '/'): string
    {
        return self::build(
            (string) config('tenancy.scheme', 'https'),
            (string) config('tenancy.root_domain'),
            config('tenancy.port'),
            $subdomain,
            $path,
        );
    }
}
