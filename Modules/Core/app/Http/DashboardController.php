<?php

declare(strict_types=1);

namespace Modules\Core\Http;

use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Dashboard\DashboardService;
use Modules\Core\Parties\Party;
use Modules\Core\Reporting\CoreCustomersMetricSource;
use Modules\Core\Reporting\DateRange;
use Modules\Core\Reporting\NoContributor;
use Modules\Core\Reporting\ReportingPipeline;
use Modules\Core\Reporting\ReportingRegistry;
use Modules\Core\Reporting\SummaryRefresher;
use Modules\Core\Terminology\TerminologyService;

final class DashboardController
{

    public function __construct(private readonly DashboardService $dashboard)
    {
    }

    /** The /dashboard page (replaces the overlay-5 placeholder). */
    public function page(Request $request, TenantContext $context): Response
    {
        return Inertia::render('Dashboard', [
            'modules' => DB::table('tenant_modules')->where('tenant_id', $context->requireId())->where('is_enabled', true)->orderBy('module_key')->pluck('module_key'),
            'widgets' => $this->dashboard->assemble($request->user('workspace'))['widgets'],
        ]);
    }

    /** ListDashboardWidgets: layout + endpoints only, never widget data. */
    public function widgets(Request $request): JsonResponse
    {
        return response()->json($this->dashboard->assemble($request->user('workspace')));
    }

    /** Core widget: total customers (labelled with the tenant's own word for them). */
    public function customers(TerminologyService $terms): JsonResponse
    {
        $recent = Party::query()->where('created_at', '>=', now('UTC')->subDays(30))->count();

        return response()->json([
            'type' => 'stat',
            'label' => $terms->resolve('Party', plural: true),
            'value' => (string) Party::query()->count(),
            'note' => "{$recent} added in the last 30 days",
        ]);
    }

    /** Core widget: new customers per day for the last 30 days, via the reporting pipeline. */
    public function customersTrend(Request $request, ReportingRegistry $registry, SummaryRefresher $refresher, ReportingPipeline $pipeline, TenantContext $context, TerminologyService $terms): JsonResponse
    {
        $tenantId = $context->requireId();
        $never = ! DB::table('report_refresh_state')->where(['tenant_id' => $tenantId, 'module_key' => 'Core', 'metric_key' => CoreCustomersMetricSource::METRIC])->whereNotNull('refreshed_through')->exists();
        if ($never) {   // first view before the scheduler has run: fill the recent window once
            $source = $registry->source('Core');
            $refresher->refresh($tenantId, $source, $source->metrics()[0], now('UTC')->subDays(34)->toDateString(), now('UTC')->toDateString());
        }

        $range = DateRange::make(now('UTC')->subDays(29)->toDateString(), now('UTC')->toDateString());
        try {
            $report = $pipeline->report(CoreCustomersMetricSource::METRIC, $range, $request->user('workspace'));
        } catch (NoContributor) {
            return response()->json(['message' => 'Not available'], 404);
        }

        return response()->json([
            'type' => 'series',
            'label' => 'New '.mb_strtolower($terms->resolve('Party', plural: true)),
            'unit' => 'count',
            'points' => $report['series'][0]['points'] ?? [],
        ]);
    }
}
