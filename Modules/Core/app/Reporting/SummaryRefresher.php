<?php

declare(strict_types=1);

namespace Modules\Core\Reporting;

use App\Kernel\Contracts\ReportMetricSource;
use App\Kernel\Dtos\ReportMetricDefinition;
use App\Kernel\Dtos\SeriesPointDTO;
use InvalidArgumentException;
use Illuminate\Support\Facades\DB;

/**
 * Fills report_summaries from a module's source for ONE tenant (the caller has set the tenant context).
 * Each metric is refreshed atomically: validated, then the window is replaced in one savepoint, so a bad
 * source output leaves the previous summary untouched.
 */
final class SummaryRefresher
{
    public const MAX_LABELS = 10;

    public function refresh(string $tenantId, ReportMetricSource $source, ReportMetricDefinition $def, string $from, string $to): int
    {
        $module = $source->moduleKey();

        return DB::transaction(function () use ($tenantId, $source, $def, $from, $to, $module): int {
            // One refresh per (tenant, module, metric) at a time.
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["{$tenantId}|{$module}|{$def->metricKey}"]);

            $points = $source->compute($tenantId, $def->metricKey, $from, $to);
            self::validate($points, $def, $from, $to);

            DB::table('report_summaries')
                ->where(['tenant_id' => $tenantId, 'module_key' => $module, 'metric_key' => $def->metricKey])
                ->whereBetween('day', [$from, $to])->delete();

            $now = now('UTC')->toDateTimeString();
            $rows = array_map(fn (SeriesPointDTO $p) => [
                'tenant_id' => $tenantId, 'metric_key' => $def->metricKey, 'module_key' => $module,
                'day' => $p->day, 'label' => $p->label, 'currency' => $p->currency ?? '', 'value' => $p->value, 'refreshed_at' => $now,
            ], $points);
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('report_summaries')->insert($chunk);
            }

            DB::table('report_refresh_state')->updateOrInsert(
                ['tenant_id' => $tenantId, 'module_key' => $module, 'metric_key' => $def->metricKey],
                ['refreshed_through' => $to, 'refreshed_at' => $now, 'last_error' => null, 'last_error_at' => null],
            );

            return count($rows);
        });
    }

    /** Records a failure without touching the existing summary. Call after the failed savepoint has rolled back. */
    public function recordFailure(string $tenantId, string $module, string $metricKey, \Throwable $e): void
    {
        DB::table('report_refresh_state')->updateOrInsert(
            ['tenant_id' => $tenantId, 'module_key' => $module, 'metric_key' => $metricKey],
            ['last_error' => mb_substr($e->getMessage(), 0, 300), 'last_error_at' => now('UTC')->toDateTimeString()],
        );
    }

    /**
     * @param list<SeriesPointDTO> $points
     * @throws InvalidArgumentException
     */
    public static function validate(array $points, ReportMetricDefinition $def, string $from, string $to): void
    {
        $seen = [];
        $labels = [];
        foreach ($points as $p) {
            if (! $p instanceof SeriesPointDTO) {
                throw new InvalidArgumentException('Source returned something other than SeriesPointDTO.');
            }
            if (DateRange::parse($p->day) === null || $p->day < $from || $p->day > $to) {
                throw new InvalidArgumentException("Point day [{$p->day}] is outside the requested window.");
            }
            if (mb_strlen($p->label) > 60) {
                throw new InvalidArgumentException('Point label is longer than 60 characters.');
            }
            if ($def->unit === ReportMetricDefinition::MONEY) {
                if ($p->currency === null || preg_match('/^[A-Z]{3}$/', $p->currency) !== 1) {
                    throw new InvalidArgumentException('Money points need an ISO 4217 currency.');
                }
            } elseif ($p->currency !== null) {
                throw new InvalidArgumentException('Count points must not carry a currency.');
            }
            $k = $p->day."\0".$p->label."\0".($p->currency ?? '');
            if (isset($seen[$k])) {
                throw new InvalidArgumentException('Duplicate point for the same day, label and currency.');
            }
            $seen[$k] = true;
            $labels[$p->label."\0".($p->currency ?? '')] = true;
            if (count($labels) > self::MAX_LABELS) {
                throw new InvalidArgumentException('A metric may have at most '.self::MAX_LABELS.' distinct labels.');
            }
        }
    }
}
