<?php

declare(strict_types=1);

namespace Modules\Core\Inbound;

/** Pure: keeps what the person just wrote, drops quoted history and the signature. Never returns empty for non-empty input. */
final class ReplyTextTrimmer
{
    public static function trim(string $text): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
        $keep = [];

        foreach ($lines as $i => $line) {
            $next = $lines[$i + 1] ?? '';
            if (self::startsQuote($line, $next)) {
                break;
            }
            $keep[] = $line;
        }

        while ($keep !== [] && (trim(end($keep)) === '' || str_starts_with(ltrim(end($keep)), '>'))) {
            array_pop($keep);
        }
        $result = trim(implode("\n", $keep));

        return $result !== '' ? $result : trim($text);
    }

    private static function startsQuote(string $line, string $next): bool
    {
        $t = trim($line);

        return preg_match('/^On .{5,200} wrote:\s*$/i', $t) === 1                                  // Gmail, Apple Mail
            || (preg_match('/^On .{5,200}$/i', $t) === 1 && preg_match('/wrote:\s*$/i', trim($next)) === 1)  // wrapped onto two lines
            || preg_match('/^-{2,}\s*Original Message\s*-{2,}$/i', $t) === 1                       // Outlook
            || preg_match('/^_{10,}$/', $t) === 1                                                  // Outlook separator
            || (preg_match('/^From:\s/i', $t) === 1 && preg_match('/^(Sent|Date):\s/i', trim($next)) === 1)  // Outlook header block
            || $line === '-- ';                                                                    // signature delimiter
    }
}
