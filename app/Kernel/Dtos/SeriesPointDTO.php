<?php

declare(strict_types=1);

namespace App\Kernel\Dtos;

use App\Kernel\Dto;

/** Core's standard time-series point. Money is an integer in minor units with its currency; never a float. */
final readonly class SeriesPointDTO extends Dto
{
    public function __construct(
        public string $day,            // 'Y-m-d' (UTC)
        public string $label,          // '' for a single unnamed series, else e.g. 'opened'
        public int $value,
        public ?string $currency = null, // ISO 4217 for money metrics, null for counts
    ) {
    }
}
