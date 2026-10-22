<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\Dto;

final readonly class UsageThresholdReachedDTO extends Dto
{
    /**
     * @param int         $used     quantity, or minor units when $currency is set
     * @param int         $limit    same unit as $used
     * @param string|null $currency set only for money metrics
     */
    public function __construct(
        public string $tenantId,
        public UsageMetric $metric,
        public int $percent,   // 80 or 100
        public int $used,
        public int $limit,
        public string $period, // YYYY-MM
        public ?string $currency = null,
    ) {
    }
}
