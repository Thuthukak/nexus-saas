<?php

declare(strict_types=1);

namespace Modules\Core\Reporting;

use App\Kernel\Dtos\ReportMetricDefinition;
use App\Platform\Modules\ModuleManager;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Support\Facades\DB;

/**
 * Cross-module reporting. Reads ONLY Core's own summary table (report_summaries), one module at a time:
 * never a module's live tables and never a join across module-owned tables. A module that is disabled
 * for the tenant (or whose metric the viewer may not see) is skipped without error.
 */
final class ReportingPipeline
{
    public const ROW_CAP = 5000;

    public function __construct(
        private readonly ReportingRegistry $registry,
        private readonly ModuleManager $modules,
        private readonly TenantContext $context,
    ) {
    }

    /**
     * Metrics the viewer can query, grouped by metric_key.
     *
     * @return list<array{metric_key:string, label:string, unit:string, modules:list<string>}>
     */
    public function availableMetrics(?Authorizable $viewer): array
    {
        $out = [];
        foreach ($this->visibleDefinitions($viewer) as [$module, $def]) {
            $out[$def->metricKey] ??= ['metric_key' => $def->metricKey, 'label' => $def->label, 'unit' => $def->unit, 'modules' => []];
            $out[$def->metricKey]['modules'][] = $module;
        }
        ksort($out);

        return array_values($out);
    }

    /**
     * @return array<string, mixed>
     * @throws NoContributor|ReportTooLarge
     */
    public function report(string $metricKey, DateRange $range, ?Authorizable $viewer): array
    {
        $tenantId = $this->context->requireId();
        $contributors = array_values(array_filter(
            $this->visibleDefinitions($viewer),
            fn (array $c) => $c[1]->metricKey === $metricKey,
        ));
        if ($contributors === []) {
            throw new NoContributor();
        }

        $monthly = $range->granularity() === 'month';
        $budget = self::ROW_CAP;
        $series = [];

        foreach ($contributors as [$module, $def]) {
            $rows = $this->rows($tenantId, $module, $metricKey, $range, $monthly, $budget + 1);
            if (count($rows) > $budget) {
                throw new ReportTooLarge();
            }
            $budget -= count($rows);

            $state = DB::table('report_refresh_state')->where(['tenant_id' => $tenantId, 'module_key' => $module, 'metric_key' => $metricKey])->first();
            $refreshedAt = $state?->refreshed_at !== null ? \Illuminate\Support\Carbon::parse($state->refreshed_at, 'UTC')->toIso8601String() : null;

            $groups = [];
            foreach ($rows as $r) {
                $groups[$r->label."\0".trim((string) $r->currency)][] = ['date' => (string) $r->day, 'value' => (int) $r->value, 'label' => $r->label, 'currency' => trim((string) $r->currency)];
            }
            if ($groups === []) {
                $groups["\0"] = [];   // contributor with no data yet: still shown, with no points
            }
            foreach ($groups as $g) {
                $label = $g[0]['label'] ?? '';
                $cur = $g[0]['currency'] ?? '';
                $key = $module.($label !== '' ? ':'.$label : '').($cur !== '' ? ' '.$cur : '');
                $series[$key] = [
                    'key' => $key, 'module_key' => $module, 'label' => $label, 'unit' => $def->unit,
                    'currency' => $cur !== '' ? $cur : null, 'refreshed_at' => $refreshedAt,
                    'points' => array_map(fn ($p) => ['date' => $p['date'], 'value' => $p['value']], $g),
                ];
            }
        }

        ksort($series);

        return [
            'metric_key' => $metricKey,
            'date_from' => $range->from,
            'date_to' => $range->to,
            'granularity' => $range->granularity(),
            'series_count' => count($series),
            'series' => array_values($series),
            'merged' => SeriesMerger::merge(array_map(fn ($s) => $s['points'], $series)),
        ];
    }

    /** @return list<object> */
    private function rows(string $tenantId, string $module, string $metricKey, DateRange $range, bool $monthly, int $limit): array
    {
        $q = DB::table('report_summaries')
            ->where('tenant_id', $tenantId)->where('metric_key', $metricKey)->where('module_key', $module)
            ->whereBetween('day', [$range->from, $range->to]);

        if ($monthly) {
            $q->selectRaw("date_trunc('month', day)::date as day, label, currency, sum(value)::bigint as value")
                ->groupByRaw("date_trunc('month', day)::date, label, currency")
                ->orderByRaw("date_trunc('month', day)::date, label, currency");
        } else {
            $q->select('day', 'label', 'currency', 'value')->orderBy('day')->orderBy('label')->orderBy('currency');
        }

        return $q->limit($limit)->get()->all();
    }

    /** @return list<array{0:string, 1:ReportMetricDefinition}> (module, definition) the tenant's enabled modules expose to this viewer */
    private function visibleDefinitions(?Authorizable $viewer): array
    {
        $active = $this->modules->activeModules($this->context->requireId());
        $out = [];
        foreach ($this->registry->sources() as $module => $source) {
            if (! in_array($module, $active, true)) {
                continue;
            }
            foreach ($source->metrics() as $def) {
                if ($def->viewPermission !== null && ($viewer === null || ! $viewer->can($def->viewPermission))) {
                    continue;
                }
                $out[] = [$module, $def];
            }
        }

        return $out;
    }
}
