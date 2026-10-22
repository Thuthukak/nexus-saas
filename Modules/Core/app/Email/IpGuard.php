<?php

declare(strict_types=1);

namespace Modules\Core\Email;

/** Pure: is this IP a public, routable address? Used so a tenant-supplied SMTP host can never aim the server at our own network. */
final class IpGuard
{
    private const V4_BLOCKED = [
        '0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24',
        '192.0.2.0/24', '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4',
    ];
    private const V6_BLOCKED = ['::/128', '::1/128', 'fc00::/7', 'fe80::/10', 'ff00::/8', '2001:db8::/32', '100::/64', '2001::/32', '2002::/16'];

    public static function isPublic(string $ip): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        if (strlen($bin) === 16) {
            // IPv4-mapped (::ffff:a.b.c.d), IPv4-compatible and NAT64 (64:ff9b::/96) carry an IPv4 address: judge that instead.
            if (str_starts_with($bin, str_repeat("\0", 10)."\xff\xff") || str_starts_with($bin, "\x00\x64\xff\x9b".str_repeat("\0", 8))) {
                return self::isPublic(inet_ntop(substr($bin, 12)));
            }
            foreach (self::V6_BLOCKED as $cidr) {
                if (self::inCidr($bin, $cidr)) {
                    return false;
                }
            }

            return true;
        }
        foreach (self::V4_BLOCKED as $cidr) {
            if (self::inCidr($bin, $cidr)) {
                return false;
            }
        }

        return true;
    }

    private static function inCidr(string $bin, string $cidr): bool
    {
        [$net, $len] = explode('/', $cidr);
        $netBin = inet_pton($net);
        if (strlen($netBin) !== strlen($bin)) {
            return false;
        }
        $len = (int) $len;
        $full = intdiv($len, 8);
        if (substr($bin, 0, $full) !== substr($netBin, 0, $full)) {
            return false;
        }
        $rest = $len % 8;
        if ($rest === 0) {
            return true;
        }
        $mask = (0xFF << (8 - $rest)) & 0xFF;

        return (ord($bin[$full]) & $mask) === (ord($netBin[$full]) & $mask);
    }
}
