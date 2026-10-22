<?php

declare(strict_types=1);

namespace Modules\Core\Console;

use App\Platform\Modules\ModuleManager;
use App\Tenancy\TenantRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Core\Reporting\ReportingRegistry;
use Modules\Core\Reporting\SummaryRefresher;
use Throwable;

/** reports:refresh-summaries — schedule every five minutes; each metric is only recomputed when it is due. */
final class RefreshSummariesCommand extends Command
{
    protected $signature = 'reports:refresh-summaries {--tenant= : Only this tenant id} {--metric= : Only this metric key} {--force : Ignore the refresh interval}';

    protected $description = 'Refresh the per-tenant report summaries from each enabled module\'s metric sources';

    public const BACKFILL_DAYS = 400;
    public const RECENT_DAYS = 35;

    public function handle(ReportingRegistry $registry, SummaryRefresher $refresher, TenantRunner $runner, ModuleManager $modules): int
    {
        $tenants = DB::table('tenants')->where('status', 'active')
            ->when($this->option('tenant'), fn ($q, $id) => $q->where('id', $id))->pluck('id');
        $failures = 0;

        foreach ($tenants as $tenantId) {
            $active = $modules->activeModules($tenantId);
            try {
                $runner->run($tenantId, function () use ($registry, $refresher, $tenantId, $active, &$failures): void {
                    foreach ($registry->sources() as $module => $source) {
                        if (! in_array($module, $active, true)) {
                            continue;
                        }
                        foreach ($source->metrics() as $def) {
                            if ($this->option('metric') && $this->option('metric') !== $def->metricKey) {
                                continue;
                            }
                            $state = DB::table('report_refresh_state')->where(['tenant_id' => $tenantId, 'module_key' => $module, 'metric_key' => $def->metricKey])->first();
                            if (! $this->option('force') && $state?->refreshed_at !== null
                                && now('UTC')->diffInMinutes(\Illuminate\Support\Carbon::parse($state->refreshed_at, 'UTC'), true) < $def->refreshEveryMinutes) {
                                continue;
                            }
                            $days = $state?->refreshed_through === null ? self::BACKFILL_DAYS : self::RECENT_DAYS;
                            try {
                                $refresher->refresh($tenantId, $source, $def, now('UTC')->subDays($days - 1)->toDateString(), now('UTC')->toDateString());
                            } catch (Throwable $e) {
                                $failures++;
                                $refresher->recordFailure($tenantId, $module, $def->metricKey, $e);
                                report($e);
                            }
                        }
                    }
                });
            } catch (Throwable $e) {
                $failures++;
                report($e);
            }
        }

        $this->info("Refreshed {$tenants->count()} tenant(s); {$failures} failure(s).");

        return self::SUCCESS;
    }
}
