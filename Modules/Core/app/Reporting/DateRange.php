<?php

declare(strict_types=1);

namespace Modules\Core\Reporting;

use DateTimeImmutable;
use InvalidArgumentException;

/** Pure: a validated inclusive UTC day range. Day granularity up to 92 days, month granularity beyond. */
final class DateRange
{
    public const MAX_DAYS = 731;
    public const DAILY_UP_TO = 92;

    private function __construct(public readonly string $from, public readonly string $to)
    {
    }

    /** @throws InvalidArgumentException keyed message: [field => message] in getMessage() is not used; see ::errors() */
    public static function make(string $from, string $to): self
    {
        $errors = self::errors($from, $to);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }

        return new self($from, $to);
    }

    /** @return array<string, string> field => message (empty when valid) */
    public static function errors(string $from, string $to): array
    {
        $errors = [];
        $a = self::parse($from);
        $b = self::parse($to);
        if ($a === null) {
            $errors['date_from'] = 'date_from must be a valid date in Y-m-d format.';
        }
        if ($b === null) {
            $errors['date_to'] = 'date_to must be a valid date in Y-m-d format.';
        }
        if ($a !== null && $b !== null) {
            if ($a > $b) {
                $errors['date_to'] = 'date_to must not be before date_from.';
            } elseif ((int) $a->diff($b)->days + 1 > self::MAX_DAYS) {
                $errors['date_to'] = 'The range may span at most '.self::MAX_DAYS.' days.';
            }
        }

        return $errors;
    }

    public function days(): int
    {
        return (int) self::parse($this->from)->diff(self::parse($this->to))->days + 1;
    }

    public function granularity(): string
    {
        return $this->days() <= self::DAILY_UP_TO ? 'day' : 'month';
    }

    public static function parse(string $value): ?DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $d !== false && $d->format('Y-m-d') === $value ? $d : null;
    }
}
