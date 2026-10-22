<?php

declare(strict_types=1);

namespace Modules\Core\Reporting;

use App\Kernel\Contracts\ReportMetricSource;
use App\Kernel\Dtos\ReportMetricDefinition;
use App\Kernel\Dtos\SeriesPointDTO;
use Illuminate\Support\Facades\DB;

/** Core's own metric: parties created per day (created_at is stored in UTC; app.timezone must stay 'UTC'). */
final class CoreCustomersMetricSource implements ReportMetricSource
{
    public const METRIC = 'customers_added';

    public function moduleKey(): string
    {
        return 'Core';
    }

    public function metrics(): array
    {
        return [new ReportMetricDefinition(self::METRIC, 'New customers', ReportMetricDefinition::COUNT, 60)];
    }

    public function compute(string $tenantId, string $metricKey, string $from, string $to): array
    {
        $rows = DB::table('core_parties')->where('tenant_id', $tenantId)
            ->whereRaw('created_at::date between ?::date and ?::date', [$from, $to])
            ->selectRaw('created_at::date as day, count(*) as n')->groupByRaw('created_at::date')->orderByRaw('1')->get();

        return $rows->map(fn ($r) => new SeriesPointDTO((string) $r->day, '', (int) $r->n))->all();
    }
}
