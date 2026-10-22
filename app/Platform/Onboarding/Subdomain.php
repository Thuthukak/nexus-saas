<?php

declare(strict_types=1);

namespace App\Platform\Onboarding;

/** Pure subdomain rules: lowercase letters, digits and hyphens, derived from company name if blank. */
final class Subdomain
{
    public const MIN = 3;
    public const MAX = 63; // DNS label limit

    public static function normalise(string $requested, string $companyName): string
    {
        $slug = static function (string $value): string {
            if (function_exists('transliterator_transliterate')) {
                $value = transliterator_transliterate('Any-Latin; Latin-ASCII', $value) ?: $value;
            }
            $value = strtolower(trim($value));
            $value = preg_replace('/[^a-z0-9-]+/', '-', $value) ?? '';
            $value = preg_replace('/-+/', '-', $value) ?? '';

            return trim($value, '-');
        };

        $result = $slug($requested);

        return $result !== '' ? $result : $slug($companyName);
    }

    public static function hasValidLength(string $subdomain): bool
    {
        $length = strlen($subdomain);

        return $length >= self::MIN && $length <= self::MAX;
    }

    /** @param list<string> $reserved */
    public static function isReserved(string $subdomain, array $reserved): bool
    {
        return in_array($subdomain, $reserved, true);
    }
}
