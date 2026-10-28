<?php

declare(strict_types=1);

namespace App\Platform\Registry;

/** Pure: which tenant status changes are allowed. Cancelled is final. */
final class StatusTransition
{
    public const STATUSES = ['active', 'suspended', 'cancelled'];

    private const ALLOWED = [
        'active' => ['suspended', 'cancelled'],
        'suspended' => ['active', 'cancelled'],
        'cancelled' => [],
    ];

    public static function allowed(string $from, string $to): bool
    {
        return in_array($to, self::ALLOWED[$from] ?? [], true);
    }
}
