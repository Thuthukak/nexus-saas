<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\Dto;

final readonly class ReportMetricDefinition extends Dto
{
    public const COUNT = 'count';
    public const MONEY = 'money';   // minor units + an explicit currency on every point

    public function __construct(
        public string $metricKey,            // e.g. 'tickets_opened'
        public string $label,                // e.g. 'Tickets opened'
        public string $unit = self::COUNT,   // count | money
        public int $refreshEveryMinutes = 60,
        public ?string $viewPermission = null, // only users with this permission see the metric
    ) {
    }
}
