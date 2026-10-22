<?php

use App\Kernel\Contracts\ReportingRegistrar;
use App\Kernel\Contracts\ReportMetricSource;
use App\Kernel\Dtos\ReportMetricDefinition;
use App\Kernel\Dtos\SeriesPointDTO;
use App\Platform\Modules\ModuleManager;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

require_once __DIR__.'/CoreTestHelpers.php';
require_once __DIR__.'/CoreHttpHelpers.php';

uses(TestCase::class, DatabaseTransactions::class);

/** A module's source with canned data per tenant: [tenantId => list<SeriesPointDTO>] (or a Closure). */
function rpSource(string $module, string $metric, array|Closure $data, string $unit = 'count', ?string $permission = null, bool $clip = true): ReportMetricSource
{
    return new class($module, $metric, $data, $unit, $permission, $clip) implements ReportMetricSource {
        public int $calls = 0;

        public function __construct(private string $m, private string $k, private array|Closure $data, private string $unit, private ?string $perm, private bool $clip) {}

        public function moduleKey(): string { return $this->m; }

        public function metrics(): array { return [new ReportMetricDefinition($this->k, ucfirst($this->k), $this->unit, 5, $this->perm)]; }

        public function compute(string $tenantId, string $metricKey, string $from, string $to): array
        {
            $this->calls++;
            $d = $this->data instanceof Closure ? ($this->data)($tenantId, $from, $to) : ($this->data[$tenantId] ?? []);

            return $this->clip
                ? array_values(array_filter($d, fn (SeriesPointDTO $p) => $p->day >= $from && $p->day <= $to))
                : array_values($d);
        }
    };
}

function rpDay(int $ago): string
{
    return now('UTC')->subDays($ago)->toDateString();
}

function rpRefresh(string $tenantId, array $opts = []): void
{
    test()->artisan('reports:refresh-summaries', ['--tenant' => $tenantId, '--force' => true] + $opts)->assertSuccessful();
}

function rpReport($t, string $host, $user, array $q)
{
    return $t->actingAs($user, 'workspace')->getJson(coreHost($host, '/core/reports?'.http_build_query($q)));
}

beforeEach(function () {
    Mail::fake();
    [$this->idA, $this->adminA] = coreHttpTenant('alpha');
    [$this->idB, $this->adminB] = coreHttpTenant('beta');
    $this->q = ['metric_key' => 'activity', 'date_from' => rpDay(10), 'date_to' => rpDay(0)];

    $this->fin = rpSource('Financial', 'activity', [
        $this->idA => [new SeriesPointDTO(rpDay(3), '', 5), new SeriesPointDTO(rpDay(1), '', 7)],
        $this->idB => [new SeriesPointDTO(rpDay(3), '', 99)],
    ]);
    $this->sup = rpSource('Support', 'activity', [
        $this->idA => [new SeriesPointDTO(rpDay(2), 'opened', 4), new SeriesPointDTO(rpDay(1), 'opened', 6), new SeriesPointDTO(rpDay(1), 'closed', 3)],
    ]);
    app(ReportingRegistrar::class)->register($this->fin);
    app(ReportingRegistrar::class)->register($this->sup);
});

it('overlays the same metric from two modules without either knowing about the other', function () {
    rpRefresh($this->idA);

    $r = rpReport($this, 'alpha', $this->adminA, $this->q)->assertOk();

    expect($r->json('series_count'))->toBe(3)
        ->and(collect($r->json('series'))->pluck('key')->all())->toBe(['Financial', 'Support:closed', 'Support:opened']);
});

it('returns the standard payload shape with a merged, ascending date axis', function () {
    rpRefresh($this->idA);
    $r = rpReport($this, 'alpha', $this->adminA, $this->q)->assertOk();

    $r->assertJsonStructure(['metric_key', 'date_from', 'date_to', 'granularity', 'series_count', 'series' => [['key', 'module_key', 'label', 'unit', 'currency', 'refreshed_at', 'points']], 'merged']);
    $dates = collect($r->json('merged'))->pluck('date')->all();
    expect($dates)->toBe([rpDay(3), rpDay(2), rpDay(1)])
        ->and($r->json('merged.2.values'))->toBe(['Financial' => 7, 'Support:closed' => 3, 'Support:opened' => 6])
        ->and($r->json('granularity'))->toBe('day');
});

it('skips a disabled module without error and returns 422 when none is left', function () {
    rpRefresh($this->idA);
    app(ModuleManager::class)->disable($this->idA, 'Support');

    $r = rpReport($this, 'alpha', $this->adminA, $this->q)->assertOk();
    expect(collect($r->json('series'))->pluck('module_key')->unique()->all())->toBe(['Financial']);

    app(ModuleManager::class)->disable($this->idA, 'Financial');
    $r = rpReport($this, 'alpha', $this->adminA, ['metric_key' => 'activity'] + $this->q)->assertStatus(422);
    expect($r->json('message'))->toBe('No enabled module contributes the requested metric_key');
});

it('returns 422 when no module has ever registered the metric', function () {
    rpReport($this, 'alpha', $this->adminA, ['metric_key' => 'nonexistent_metric'] + $this->q)->assertStatus(422)
        ->assertJsonPath('message', 'No enabled module contributes the requested metric_key');
});

it('returns 422 "Validation failed" for bad input', function (array $override, string $field) {
    $r = rpReport($this, 'alpha', $this->adminA, $override + $this->q)->assertStatus(422);

    expect($r->json('message'))->toBe('Validation failed')->and($r->json('errors'))->toHaveKey($field);
})->with([
    'missing metric'  => [['metric_key' => ''], 'metric_key'],
    'bad metric'      => [['metric_key' => 'Bad Key!'], 'metric_key'],
    'bad from'        => [['date_from' => '2026-13-01'], 'date_from'],
    'bad to'          => [['date_to' => 'yesterday'], 'date_to'],
    'reversed'        => [['date_from' => '2026-02-02', 'date_to' => '2026-02-01'], 'date_to'],
    'too long'        => [['date_from' => '2020-01-01', 'date_to' => '2026-01-01'], 'date_to'],
]);

it('requires a signed-in workspace user', function () {
    $this->getJson(coreHost('alpha', '/core/reports?'.http_build_query($this->q)))->assertUnauthorized();
});

it('never leaks another tenant\'s numbers (RLS and tenant scoping)', function () {
    rpRefresh($this->idA);
    rpRefresh($this->idB);

    $b = rpReport($this, 'beta', $this->adminB, $this->q)->assertOk();
    expect(collect($b->json('merged'))->pluck('values')->flatMap(fn ($v) => $v)->all())->toBe(['Financial' => 99]);

    app(TenantContext::class)->set($this->idA);
    expect(DB::table('report_summaries')->where('tenant_id', $this->idB)->count())->toBe(0);   // invisible under A's context
});

it('hides a metric the viewer lacks permission for, from the report and the metric list', function () {
    app(ReportingRegistrar::class)->register(rpSource('Financial', 'revenue', [$this->idA => [new SeriesPointDTO(rpDay(1), '', 1000, 'ZAR')]], 'money', 'financial.reports.view'));
    rpRefresh($this->idA);
    $plain = (function () { app(TenantContext::class)->set($this->idA); return coreHttpPlainUser('alpha'); })->call($this);

    rpReport($this, 'alpha', $plain, ['metric_key' => 'revenue'] + $this->q)->assertStatus(422);
    expect(collect($this->actingAs($plain, 'workspace')->getJson(coreHost('alpha', '/core/reports/metrics'))->json('metrics'))->pluck('metric_key')->all())->not->toContain('revenue');
});

it('carries money as integer minor units with an explicit currency on the series', function () {
    app(ReportingRegistrar::class)->register(rpSource('Financial', 'revenue', [$this->idA => [new SeriesPointDTO(rpDay(1), '', 123456, 'ZAR'), new SeriesPointDTO(rpDay(1), 'x', 5, 'USD')]], 'money'));
    rpRefresh($this->idA);

    $r = rpReport($this, 'alpha', $this->adminA, ['metric_key' => 'revenue'] + $this->q)->assertOk();
    $zar = collect($r->json('series'))->firstWhere('key', 'Financial ZAR');

    expect($zar['currency'])->toBe('ZAR')->and($zar['unit'])->toBe('money')->and($zar['points'][0]['value'])->toBe(123456)
        ->and(collect($r->json('series'))->pluck('key')->all())->toContain('Financial:x USD');   // currencies are never summed together
});

it('aggregates by month beyond 92 days', function () {
    app(ReportingRegistrar::class)->register(rpSource('Financial', 'bulk', fn ($t) => [
        new SeriesPointDTO(now('UTC')->subDays(100)->startOfMonth()->addDays(1)->toDateString(), '', 2),
        new SeriesPointDTO(now('UTC')->subDays(100)->startOfMonth()->addDays(2)->toDateString(), '', 3),
    ]));
    rpRefresh($this->idA, ['--metric' => 'bulk']);

    $r = rpReport($this, 'alpha', $this->adminA, ['metric_key' => 'bulk', 'date_from' => rpDay(200), 'date_to' => rpDay(0)])->assertOk();

    expect($r->json('granularity'))->toBe('month')->and($r->json('series.0.points'))->toHaveCount(1)->and($r->json('series.0.points.0.value'))->toBe(5);
});

it('caps a report that would return too many rows', function () {
    app(TenantContext::class)->set($this->idA);
    DB::table('report_summaries')->insert(array_map(fn ($i) => [
        'tenant_id' => $this->idA, 'metric_key' => 'activity', 'module_key' => 'Financial', 'day' => now('UTC')->subDays(5)->toDateString(),
        'label' => "l{$i}", 'currency' => '', 'value' => 1, 'refreshed_at' => now(),
    ], range(1, 5001)));

    rpReport($this, 'alpha', $this->adminA, $this->q)->assertStatus(422)->assertJsonPath('message', 'Report too large: narrow the date range');
});

// ---- refresher ----

it('replaces the window on refresh and leaves days outside it alone', function () {
    app(TenantContext::class)->set($this->idA);
    DB::table('report_summaries')->insert(['tenant_id' => $this->idA, 'metric_key' => 'activity', 'module_key' => 'Financial', 'day' => rpDay(2000), 'label' => '', 'currency' => '', 'value' => 42, 'refreshed_at' => now()]);
    rpRefresh($this->idA);
    rpRefresh($this->idA);   // twice: idempotent, no duplicates

    app(TenantContext::class)->set($this->idA);
    expect(DB::table('report_summaries')->where('module_key', 'Financial')->count())->toBe(3)
        ->and(DB::table('report_summaries')->where('day', rpDay(2000))->value('value'))->toBe(42);
});

it('keeps the previous summary and records the error when a source returns invalid data', function () {
    rpRefresh($this->idA);
    app(ReportingRegistrar::class)->register(rpSource('Financial', 'activity', fn () => [new SeriesPointDTO('1999-01-01', '', 1)], clip: false));
    rpRefresh($this->idA);

    app(TenantContext::class)->set($this->idA);
    expect(DB::table('report_summaries')->where('module_key', 'Financial')->where('metric_key', 'activity')->count())->toBe(2)
        ->and(DB::table('report_refresh_state')->where('module_key', 'Financial')->value('last_error'))->toContain('outside the requested window');
});

it('keeps refreshing other metrics and tenants when one source throws', function () {
    app(ReportingRegistrar::class)->register(rpSource('Support', 'activity', fn () => throw new RuntimeException('boom')));
    rpRefresh($this->idA);

    app(TenantContext::class)->set($this->idA);
    expect(DB::table('report_summaries')->where('module_key', 'Financial')->count())->toBe(2)
        ->and(DB::table('report_refresh_state')->where('module_key', 'Support')->value('last_error'))->toBe('boom');
});

it('does not recompute a metric that is not yet due, but does with --force', function () {
    $this->artisan('reports:refresh-summaries', ['--tenant' => $this->idA])->assertSuccessful();
    $calls = $this->fin->calls;
    $this->artisan('reports:refresh-summaries', ['--tenant' => $this->idA])->assertSuccessful();
    expect($this->fin->calls)->toBe($calls);

    rpRefresh($this->idA);
    expect($this->fin->calls)->toBe($calls + 1);
});

it('does not refresh a module that is disabled for the tenant', function () {
    app(ModuleManager::class)->disable($this->idA, 'Support');
    rpRefresh($this->idA);

    expect($this->sup->calls)->toBe(0);
});

it('leaves the tenant context as it found it', function () {
    app(TenantContext::class)->set($this->idB);
    rpRefresh($this->idA);

    expect(app(TenantContext::class)->id())->toBe($this->idB);
});

it('stores summaries as tenant-scoped rows: the table has RLS and rejects a mixed unit row', function () {
    expect(DB::selectOne("select relrowsecurity as r from pg_class where relname = 'report_summaries'")->r)->toBeTrue();

    app(TenantContext::class)->set($this->idA);
    expect(fn () => coreInSavepoint(fn () => DB::table('report_summaries')->insert([
        'tenant_id' => $this->idA, 'metric_key' => 'activity', 'module_key' => 'Financial', 'day' => rpDay(1),
        'label' => '', 'currency' => 'zar', 'value' => 1, 'refreshed_at' => now(),
    ])))->toThrow(\Illuminate\Database\QueryException::class);
});

it('lists available metrics and serves the reports page', function () {
    $m = $this->actingAs($this->adminA, 'workspace')->getJson(coreHost('alpha', '/core/reports/metrics'))->assertOk()->json('metrics');
    $activity = collect($m)->firstWhere('metric_key', 'activity');

    expect($activity['modules'])->toBe(['Financial', 'Support'])
        ->and(collect($m)->pluck('metric_key')->all())->toContain('customers_added');

    $this->actingAs($this->adminA, 'workspace')->get(coreHost('alpha', '/reports'))->assertOk()->assertInertia(fn ($p) => $p->component('Core/Reports')->has('metrics'));
});
