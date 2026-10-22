<?php

declare(strict_types=1);

namespace Modules\Core\Records;

use InvalidArgumentException;

/** Pure rules for a module's metadata blob: a JSON OBJECT, bounded in size and depth. */
final class MetadataValidator
{
    public const MAX_BYTES = 65536;
    public const MAX_DEPTH = 6;

    /**
     * Accepts a PHP array (already decoded) or a JSON string (the spec's `metadata` is a string).
     *
     * @return array<string, mixed>
     * @throws InvalidArgumentException
     */
    public static function normalise(mixed $input): array
    {
        if (is_string($input)) {
            if (strlen($input) > self::MAX_BYTES) {
                throw new InvalidArgumentException('Metadata is too large (limit 64 KB).');
            }
            try {
                $decoded = json_decode($input, false, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new InvalidArgumentException('Metadata must be valid JSON no deeper than '.self::MAX_DEPTH.' levels.');
            }
            if (! is_object($decoded)) {
                throw new InvalidArgumentException('Metadata must be a JSON object.');
            }
            $input = json_decode($input, true, self::MAX_DEPTH + 1, JSON_THROW_ON_ERROR);
        }

        if (! is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new InvalidArgumentException('Metadata must be a JSON object.');
        }

        $json = json_encode($input, JSON_THROW_ON_ERROR);
        if (strlen($json) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Metadata is too large (limit 64 KB).');
        }
        if (self::depth($input) > self::MAX_DEPTH) {
            throw new InvalidArgumentException('Metadata is nested too deeply (limit '.self::MAX_DEPTH.' levels).');
        }

        return $input;
    }

    /** Nesting levels of arrays/objects: {"a":1} is 1, {"a":{"b":1}} is 2 (same as json_decode's depth). */
    private static function depth(mixed $v): int
    {
        if (! is_array($v)) {
            return 0;
        }

        return 1 + ($v === [] ? 0 : max(array_map(fn ($x) => self::depth($x), $v)));
    }
}
