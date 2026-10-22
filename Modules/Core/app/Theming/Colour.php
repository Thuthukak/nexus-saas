<?php

declare(strict_types=1);

namespace Modules\Core\Theming;

/** Pure colour maths (no framework). WCAG 2.x relative luminance and contrast ratio. */
final class Colour
{
    /** Returns lowercase #rrggbb for #rgb / #rrggbb input, or null when malformed. */
    public static function normaliseHex(string $value): ?string
    {
        $v = strtolower(trim($value));
        if (preg_match('/^#([0-9a-f]{3})$/', $v, $m)) {
            return '#'.$m[1][0].$m[1][0].$m[1][1].$m[1][1].$m[1][2].$m[1][2];
        }

        return preg_match('/^#[0-9a-f]{6}$/', $v) === 1 ? $v : null;
    }

    public static function luminance(string $hex): float
    {
        $hex = self::normaliseHex($hex) ?? throw new \InvalidArgumentException("Bad colour [{$hex}].");
        $channels = [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
        [$r, $g, $b] = array_map(static function (int $c): float {
            $s = $c / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, $channels);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function contrast(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Text colour (white or near-black) with the higher contrast on this background. */
    public static function readableOn(string $background): string
    {
        return self::contrast($background, '#ffffff') >= self::contrast($background, '#111111') ? '#ffffff' : '#111111';
    }
}
