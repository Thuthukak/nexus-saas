<?php

declare(strict_types=1);

namespace Modules\Core\Dashboard;

/** Pure 12-column grid placement. Widgets keep their requested spot unless it is taken; then they move down. */
final class GridLayout
{
    public const COLUMNS = 12;

    /**
     * @param  list<array{id:string, x:int, y:int, w:int, h:int}>  $widgets
     * @return list<array{id:string, x:int, y:int, w:int, h:int}> same widgets, collision-free, in placement order
     */
    public static function resolve(array $widgets): array
    {
        usort($widgets, fn ($a, $b) => [$a['y'], $a['x'], $a['id']] <=> [$b['y'], $b['x'], $b['id']]);

        $placed = [];
        foreach ($widgets as $widget) {
            $guard = 0;
            while (self::collides($widget, $placed) && $guard++ < 10000) {
                $widget['y']++;
            }
            $placed[] = $widget;
        }

        return $placed;
    }

    /** @param list<array{x:int,y:int,w:int,h:int}> $placed */
    private static function collides(array $c, array $placed): bool
    {
        foreach ($placed as $p) {
            if ($c['x'] < $p['x'] + $p['w'] && $p['x'] < $c['x'] + $c['w'] && $c['y'] < $p['y'] + $p['h'] && $p['y'] < $c['y'] + $c['h']) {
                return true;
            }
        }

        return false;
    }
}
