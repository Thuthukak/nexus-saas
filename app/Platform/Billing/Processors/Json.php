<?php

declare(strict_types=1);

namespace App\Platform\Billing\Processors;

use InvalidArgumentException;

/** Shared strict helpers for adapters. */
final class Json
{
    /** @return array<string, mixed> */
    public static function object(string $payload): array
    {
        $d = json_decode($payload, true, 32);
        if (! is_array($d) || array_is_list($d)) {
            throw new InvalidArgumentException('Payload is not a JSON object.');
        }

        return $d;
    }

    public static function str(mixed $v, int $max = 120): ?string
    {
        return is_string($v) && $v !== '' && strlen($v) <= $max ? $v : null;
    }

    public static function minor(mixed $v): ?int
    {
        return is_int($v) && $v >= 0 ? $v : null;
    }

    public static function uuid(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $v) === 1 ? strtolower($v) : null;
    }

    public static function ts(mixed $v): ?\DateTimeImmutable
    {
        return is_int($v) && $v > 0 && $v < 4102444800 ? (new \DateTimeImmutable('@'.$v)) : null;
    }

    public static function currency(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^[A-Za-z]{3}$/D', $v) === 1 ? strtoupper($v) : null;
    }

    /** Header lookup, case-insensitive; accepts Laravel's array-of-values form. */
    public static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $k => $v) {
            if (strcasecmp((string) $k, $name) === 0) {
                $v = is_array($v) ? ($v[0] ?? null) : $v;

                return is_string($v) ? $v : null;
            }
        }

        return null;
    }
}
