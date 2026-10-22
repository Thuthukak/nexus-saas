<?php

declare(strict_types=1);

namespace Modules\Core\Reporting;

/** Pure: overlays several named series on one date axis. A series with no point on a date simply has no value there. */
final class SeriesMerger
{
    /**
     * @param  array<string, list<array{date:string, value:int}>>  $series  seriesKey => points
     * @return list<array{date:string, values:array<string,int>}> ascending by date
     */
    public static function merge(array $series): array
    {
        $byDate = [];
        foreach ($series as $key => $points) {
            foreach ($points as $p) {
                $byDate[$p['date']][(string) $key] = $p['value'];
            }
        }
        ksort($byDate);

        $out = [];
        foreach ($byDate as $date => $values) {
            ksort($values);
            $out[] = ['date' => (string) $date, 'values' => $values];
        }

        return $out;
    }
}
