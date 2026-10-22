<?php

declare(strict_types=1);

namespace Modules\Core\Terminology;

/** Pure helpers for matching a free-text business type and for naive English plurals. */
final class TerminologyKey
{
    /** "Professional Services" -> "professional-services". Empty input -> "". */
    public static function fromBusinessType(?string $businessType): string
    {
        $v = strtolower(trim((string) $businessType));
        $v = preg_replace('/[^a-z0-9]+/', '-', $v) ?? '';

        return trim($v, '-');
    }

    public static function pluralise(string $singular): string
    {
        $s = trim($singular);
        if ($s === '') {
            return $s;
        }
        if (preg_match('/[^aeiou]y$/i', $s)) {
            return substr($s, 0, -1).'ies';
        }
        if (preg_match('/(s|x|z|ch|sh)$/i', $s)) {
            return $s.'es';
        }

        return $s.'s';
    }

    /** A display term: 1-40 letters (any language), spaces, hyphens, apostrophes. No markup, digits or symbols. */
    public static function isValidTerm(string $term): bool
    {
        $t = trim($term);

        return $t !== '' && mb_strlen($t) <= 40 && preg_match("/^[\\p{L}][\\p{L}\\s'’\\-]*$/u", $t) === 1;
    }
}
