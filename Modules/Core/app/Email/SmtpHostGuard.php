<?php

declare(strict_types=1);

namespace Modules\Core\Email;

use Closure;
use InvalidArgumentException;

/**
 * Server-side request forgery defence for tenant-supplied SMTP relays. The host must resolve ONLY to public
 * addresses and the port must be a standard submission port. The caller connects to the returned IP (not the
 * name), so DNS cannot change between the check and the connection.
 */
final class SmtpHostGuard
{
    public const ALLOWED_PORTS = [25, 465, 587, 2525];

    /** @var Closure(string): list<string> */
    private readonly Closure $resolver;

    /** @param (Closure(string): list<string>)|null $resolver host => IP addresses (injectable for tests) */
    public function __construct(?Closure $resolver = null)
    {
        $this->resolver = $resolver ?? static function (string $host): array {
            $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

            return array_values(array_filter(array_map(fn ($r) => $r['ip'] ?? $r['ipv6'] ?? null, $records)));
        };
    }

    /** @throws InvalidArgumentException with a message safe to show the tenant admin */
    public function resolve(string $host, int $port): string
    {
        $host = strtolower(trim($host));
        if (! in_array($port, self::ALLOWED_PORTS, true)) {
            throw new InvalidArgumentException('The SMTP port must be one of '.implode(', ', self::ALLOWED_PORTS).'.');
        }
        if ($host === '' || strlen($host) > 253 || preg_match('/[\s\x00-\x1f]/', $host)) {
            throw new InvalidArgumentException('The SMTP host is not valid.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $ips = [$host];
        } else {
            if (preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host) !== 1) {
                throw new InvalidArgumentException('The SMTP host must be a full host name such as smtp.example.com.');
            }
            $ips = ($this->resolver)($host);
        }

        if ($ips === []) {
            throw new InvalidArgumentException('The SMTP host could not be resolved.');
        }
        foreach ($ips as $ip) {
            if (! IpGuard::isPublic($ip)) {
                throw new InvalidArgumentException('The SMTP host must be a public internet address.');
            }
        }

        return $ips[0];
    }
}
