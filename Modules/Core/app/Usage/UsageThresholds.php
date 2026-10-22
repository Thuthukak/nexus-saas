<?php

declare(strict_types=1);

namespace Modules\Core\Usage;

/** Pure threshold maths. A null limit means unlimited (Enterprise) and never warns. */
final class UsageThresholds
{
    public const WARN = 80;
    public const FULL = 100;

    /** @return list<int> thresholds (ascending) that $used has reached against $limit */
    public static function levelsReached(int $used, ?int $limit): array
    {
        if ($limit === null || $limit <= 0) {
            return [];
        }

        $levels = [];
        // Integer maths: used/limit >= 80%  <=>  used*100 >= limit*80
        if ($used * 100 >= $limit * self::WARN) {
            $levels[] = self::WARN;
        }
        if ($used >= $limit) {
            $levels[] = self::FULL;
        }

        return $levels;
    }
}
