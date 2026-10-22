<?php

declare(strict_types=1);

namespace Modules\Core\Inbound;

/** Pure: raw RFC 5322 header block -> lower-cased name => list of unfolded, decoded values. */
final class RawHeaderParser
{
    /** @return array<string, list<string>> */
    public static function parse(string $raw): array
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $raw = explode("\n\n", $raw, 2)[0];                          // headers only
        $raw = preg_replace("/\n[ \t]+/", ' ', $raw) ?? $raw;       // unfold continuation lines

        $out = [];
        foreach (explode("\n", $raw) as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name = strtolower(trim($name));
            if ($name === '' || preg_match('/^[a-z0-9\-]+$/', $name) !== 1) {
                continue;
            }
            $out[$name][] = self::decode(trim($value));
        }

        return $out;
    }

    /** @return list<string> lower-cased addresses found in an address header value */
    public static function addresses(string $value): array
    {
        preg_match_all('/[A-Za-z0-9._%+\-=]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', $value, $m);

        return array_values(array_unique(array_map('strtolower', $m[0])));
    }

    public static function displayName(string $value): ?string
    {
        if (preg_match('/^\s*"?([^"<]+?)"?\s*</', $value, $m) === 1) {
            $name = trim($m[1]);

            return $name === '' ? null : mb_substr($name, 0, 100);
        }

        return null;
    }

    private static function decode(string $v): string
    {
        if (str_contains($v, '=?') && function_exists('iconv_mime_decode')) {
            $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($d !== false) {
                return $d;
            }
        }

        return $v;
    }
}
