<?php

declare(strict_types=1);

namespace App\Kernel\Contracts;

use App\Kernel\Dtos\ReportMetricDefinition;
use App\Kernel\Dtos\SeriesPointDTO;

/**
 * How a module maps its own data onto Core's standard time-series schema (date, label, value).
 * Modules never know about each other: two modules may contribute the same metric_key and Core
 * overlays them.
 *
 * compute() is called by Core's scheduled refresher (never by a user request) inside the tenant's
 * RLS context, and may read the module's own live tables. Core stores the result in its own
 * tenant-scoped, indexed summary table; reports read ONLY that summary.
 */
interface ReportMetricSource
{
    public function moduleKey(): string;

    /** @return list<ReportMetricDefinition> */
    public function metrics(): array;

    /**
     * One point per (day, label) between $from and $to inclusive (UTC days, 'Y-m-d'). Days with no
     * activity may be omitted. At most 10 distinct labels per metric.
     *
     * @return list<SeriesPointDTO>
     */
    public function compute(string $tenantId, string $metricKey, string $from, string $to): array;
}
