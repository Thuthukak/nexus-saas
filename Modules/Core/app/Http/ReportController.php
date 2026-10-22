<?php

declare(strict_types=1);

namespace Modules\Core\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Core\Reporting\DateRange;
use Modules\Core\Reporting\NoContributor;
use Modules\Core\Reporting\ReportingPipeline;
use Modules\Core\Reporting\ReportTooLarge;

final class ReportController
{
    public function __construct(private readonly ReportingPipeline $pipeline)
    {
    }

    public function page(Request $request): Response
    {
        return Inertia::render('Core/Reports', ['metrics' => $this->pipeline->availableMetrics($request->user('workspace'))]);
    }

    public function metrics(Request $request): JsonResponse
    {
        return response()->json(['metrics' => $this->pipeline->availableMetrics($request->user('workspace'))]);
    }

    /** GetCrossModuleReport: 200 | 422 (bad input, or no enabled module contributes the metric). */
    public function show(Request $request): JsonResponse
    {
        $metric = (string) $request->query('metric_key', '');
        $from = (string) $request->query('date_from', '');
        $to = (string) $request->query('date_to', '');

        $errors = DateRange::errors($from, $to);
        if (preg_match('/^[a-z][a-z0-9_.]{1,59}$/', $metric) !== 1) {
            $errors = ['metric_key' => 'metric_key is required and must be a valid metric key.'] + $errors;
        }
        if ($errors !== []) {
            return response()->json(['message' => 'Validation failed', 'errors' => array_map(fn ($m) => [$m], $errors)], 422);
        }

        try {
            return response()->json($this->pipeline->report($metric, DateRange::make($from, $to), $request->user('workspace')));
        } catch (NoContributor|ReportTooLarge $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
