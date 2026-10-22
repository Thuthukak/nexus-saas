<?php

declare(strict_types=1);

namespace Modules\Core\Email;

/** Lower-case RFC 4648 base32, no padding. Used because mail systems may change the case of an address. */
final class Base32
{
    private const ALPHABET = 'abcdefghijklmnopqrstuvwxyz234567';

    public static function encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    /** Strict: null for characters outside the alphabet or non-canonical trailing bits. */
    public static function decode(string $text): ?string
    {
        $bits = '';
        foreach (str_split($text) as $c) {
            $i = strpos(self::ALPHABET, $c);
            if ($i === false) {
                return null;
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $rem = strlen($bits) % 8;
        if ($rem >= 5 || ($rem > 0 && bindec(substr($bits, -$rem)) !== 0)) {
            return null;
        }
        $bits = substr($bits, 0, strlen($bits) - $rem);
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            $out .= chr(bindec($byte));
        }

        return $out;
    }
}
