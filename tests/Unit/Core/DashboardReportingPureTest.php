<?php

use App\Kernel\Contracts\ReportMetricSource;
use App\Kernel\Dtos\ReportMetricDefinition;
use App\Kernel\Dtos\SeriesPointDTO;
use Modules\Core\Dashboard\DashboardRegistry;
use Modules\Core\Dashboard\GridLayout;
use Modules\Core\Reporting\DateRange;
use Modules\Core\Reporting\ReportingRegistry;
use Modules\Core\Reporting\SeriesMerger;
use Modules\Core\Reporting\SummaryRefresher;

function prSource(string $module, array $metrics): ReportMetricSource
{
    return new class($module, $metrics) implements ReportMetricSource {
        public function __construct(private string $m, private array $defs) {}
        public function moduleKey(): string { return $this->m; }
        public function metrics(): array { return $this->defs; }
        public function compute(string $tenantId, string $metricKey, string $from, string $to): array { return []; }
    };
}

// ---- grid ----

it('keeps non-overlapping widgets where they asked to be', function () {
    $out = GridLayout::resolve([
        ['id' => 'b', 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 2],
        ['id' => 'a', 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 2],
    ]);

    expect(array_column($out, 'id'))->toBe(['a', 'b'])->and($out[1]['x'])->toBe(6)->and($out[1]['y'])->toBe(0);
});

it('moves a colliding widget down until it fits, deterministically', function () {
    $in = [
        ['id' => 'z', 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 2],
        ['id' => 'a', 'x' => 2, 'y' => 0, 'w' => 4, 'h' => 3],
    ];
    $out = GridLayout::resolve($in);

    expect($out[0]['id'])->toBe('z')                                   // same (y,x)? z has x=0 so it sorts first
        ->and($out[1]['id'])->toBe('a')->and($out[1]['y'])->toBe(2)
        ->and(GridLayout::resolve(array_reverse($in)))->toBe($out);   // input order does not matter
});

it('never returns overlapping rectangles', function () {
    $in = [];
    for ($i = 0; $i < 12; $i++) {
        $in[] = ['id' => "w{$i}", 'x' => ($i * 3) % 9, 'y' => $i % 2, 'w' => 4, 'h' => 2 + ($i % 3)];
    }
    $out = GridLayout::resolve($in);

    foreach ($out as $i => $p) {
        foreach ($out as $j => $q) {
            if ($i < $j) {
                $overlap = $p['x'] < $q['x'] + $q['w'] && $q['x'] < $p['x'] + $p['w'] && $p['y'] < $q['y'] + $q['h'] && $q['y'] < $p['y'] + $p['h'];
                expect($overlap)->toBeFalse();
            }
        }
    }
    expect($out)->toHaveCount(12);
});

// ---- widget registry validation ----

it('registers a widget and namespaces its id by module', function () {
    $r = new DashboardRegistry();
    $r->register('Support', 'open-tickets', 'Open tickets', '/support/widgets/open-tickets', 0, 2, 4, 2);

    expect($r->all()[0]['id'])->toBe('support.open-tickets');
});

it('replaces a re-registered widget of the same module', function () {
    $r = new DashboardRegistry();
    $r->register('Support', 'a-widget', 'One', '/support/x', 0, 0, 4, 2);
    $r->register('Support', 'a-widget', 'Two', '/support/x', 0, 0, 4, 2);

    expect($r->all())->toHaveCount(1)->and($r->all()[0]['title'])->toBe('Two');
});

it('refuses bad widget definitions', function (array $args) {
    $r = new DashboardRegistry();
    expect(fn () => $r->register(...$args))->toThrow(InvalidArgumentException::class);
})->with([
    'unknown module'        => [['Nope', 'abc', 'T', '/nope/x', 0, 0, 4, 2]],
    'bad id'                => [['Support', 'A B', 'T', '/support/x', 0, 0, 4, 2]],
    'empty title'           => [['Support', 'abc', '  ', '/support/x', 0, 0, 4, 2]],
    'endpoint other module' => [['Support', 'abc', 'T', '/financial/x', 0, 0, 4, 2]],
    'endpoint absolute url' => [['Support', 'abc', 'T', 'https://evil.test/support/x', 0, 0, 4, 2]],
    'endpoint protocol rel' => [['Support', 'abc', 'T', '//evil.test/support/x', 0, 0, 4, 2]],
    'endpoint traversal'    => [['Support', 'abc', 'T', '/support/../core/x', 0, 0, 4, 2]],
    'endpoint no prefix'    => [['Support', 'abc', 'T', '/supportive/x', 0, 0, 4, 2]],
    'x + w beyond grid'     => [['Support', 'abc', 'T', '/support/x', 9, 0, 4, 2]],
    'zero width'            => [['Support', 'abc', 'T', '/support/x', 0, 0, 0, 2]],
    'negative y'            => [['Support', 'abc', 'T', '/support/x', 0, -1, 4, 2]],
    'zero height'           => [['Support', 'abc', 'T', '/support/x', 0, 0, 4, 0]],
]);

// ---- date range ----

it('accepts a valid range and picks the granularity', function () {
    expect(DateRange::make('2026-01-01', '2026-03-03')->granularity())->toBe('day')   // 62 days
        ->and(DateRange::make('2026-01-01', '2026-04-02')->granularity())->toBe('day') // exactly 92
        ->and(DateRange::make('2026-01-01', '2026-04-03')->granularity())->toBe('month') // 93
        ->and(DateRange::make('2026-05-05', '2026-05-05')->days())->toBe(1);
});

it('rejects malformed, impossible, reversed and over-long ranges', function (string $from, string $to, string $field) {
    expect(DateRange::errors($from, $to))->toHaveKey($field);
})->with([
    ['2026-1-1', '2026-02-01', 'date_from'],
    ['2026-02-30', '2026-03-01', 'date_from'],
    ['', '2026-03-01', 'date_from'],
    ['2026-03-01', 'tomorrow', 'date_to'],
    ['2026-03-02', '2026-03-01', 'date_to'],
    ['2024-01-01', '2026-01-01', 'date_to'],
]);

it('allows exactly 731 days and rejects 732', function () {
    expect(DateRange::errors('2024-12-31', '2026-12-31'))->toBe([]);               // 731 days
    expect(DateRange::errors('2024-12-30', '2026-12-31'))->toHaveKey('date_to');   // 732 days
});

// ---- merger ----

it('overlays series on one ascending date axis; a missing day is absent, not zero', function () {
    $m = SeriesMerger::merge([
        'Support' => [['date' => '2026-01-03', 'value' => 5], ['date' => '2026-01-01', 'value' => 2]],
        'Financial' => [['date' => '2026-01-02', 'value' => 9], ['date' => '2026-01-03', 'value' => 1]],
    ]);

    expect($m)->toBe([
        ['date' => '2026-01-01', 'values' => ['Support' => 2]],
        ['date' => '2026-01-02', 'values' => ['Financial' => 9]],
        ['date' => '2026-01-03', 'values' => ['Financial' => 1, 'Support' => 5]],
    ])->and(SeriesMerger::merge([]))->toBe([]);
});

// ---- reporting registry ----

it('accepts valid metrics and replaces a module\'s own re-registration', function () {
    $r = new ReportingRegistry();
    $r->register(prSource('Support', [new ReportMetricDefinition('tickets_opened', 'Tickets opened')]));
    $r->register(prSource('Support', [new ReportMetricDefinition('tickets_closed', 'Tickets closed')]));

    expect($r->sources())->toHaveCount(1)->and($r->source('Support')->metrics()[0]->metricKey)->toBe('tickets_closed');
});

it('lets two modules share a metric key when the unit agrees, and refuses when it differs', function () {
    $r = new ReportingRegistry();
    $r->register(prSource('Support', [new ReportMetricDefinition('activity', 'Activity')]));
    $r->register(prSource('Financial', [new ReportMetricDefinition('activity', 'Activity')]));
    expect($r->sources())->toHaveCount(2);

    $r2 = new ReportingRegistry();
    $r2->register(prSource('Support', [new ReportMetricDefinition('activity', 'Activity', 'count')]));
    expect(fn () => $r2->register(prSource('Financial', [new ReportMetricDefinition('activity', 'Activity', 'money')])))->toThrow(InvalidArgumentException::class);
});

it('refuses invalid metric definitions', function (ReportMetricDefinition $def) {
    expect(fn () => (new ReportingRegistry())->register(prSource('Support', [$def])))->toThrow(InvalidArgumentException::class);
})->with([
    'bad key'      => [new ReportMetricDefinition('Bad Key', 'L')],
    'short key'    => [new ReportMetricDefinition('a', 'L')],
    'empty label'  => [new ReportMetricDefinition('good_key', '')],
    'bad unit'     => [new ReportMetricDefinition('good_key', 'L', 'bananas')],
    'too frequent' => [new ReportMetricDefinition('good_key', 'L', 'count', 1)],
    'too rare'     => [new ReportMetricDefinition('good_key', 'L', 'count', 5000)],
]);

it('refuses a source for an unknown module', function () {
    expect(fn () => (new ReportingRegistry())->register(prSource('Nope', [])))->toThrow(InvalidArgumentException::class);
});

// ---- point validation ----

it('validates source output before anything is stored', function (array $points, ReportMetricDefinition $def, bool $ok) {
    $run = fn () => SummaryRefresher::validate($points, $def, '2026-01-01', '2026-01-31');
    $ok ? expect($run())->toBeNull() : expect($run)->toThrow(InvalidArgumentException::class);
})->with(function () {
    $count = new ReportMetricDefinition('opened', 'Opened');
    $money = new ReportMetricDefinition('revenue', 'Revenue', 'money');

    return [
        'valid counts'          => [[new SeriesPointDTO('2026-01-02', '', 3), new SeriesPointDTO('2026-01-03', '', 0)], $count, true],
        'no points'             => [[], $count, true],
        'valid money'           => [[new SeriesPointDTO('2026-01-02', '', 1000, 'ZAR')], $money, true],
        'day before window'     => [[new SeriesPointDTO('2025-12-31', '', 1)], $count, false],
        'day after window'      => [[new SeriesPointDTO('2026-02-01', '', 1)], $count, false],
        'impossible date'       => [[new SeriesPointDTO('2026-01-32', '', 1)], $count, false],
        'duplicate'             => [[new SeriesPointDTO('2026-01-02', 'a', 1), new SeriesPointDTO('2026-01-02', 'a', 2)], $count, false],
        'same day other label'  => [[new SeriesPointDTO('2026-01-02', 'a', 1), new SeriesPointDTO('2026-01-02', 'b', 2)], $count, true],
        'long label'            => [[new SeriesPointDTO('2026-01-02', str_repeat('x', 61), 1)], $count, false],
        'money without currency' => [[new SeriesPointDTO('2026-01-02', '', 100)], $money, false],
        'money bad currency'    => [[new SeriesPointDTO('2026-01-02', '', 100, 'zar')], $money, false],
        'count with currency'   => [[new SeriesPointDTO('2026-01-02', '', 1, 'ZAR')], $count, false],
        'eleven labels'         => [array_map(fn ($i) => new SeriesPointDTO('2026-01-02', "l{$i}", 1), range(1, 11)), $count, false],
        'ten labels'            => [array_map(fn ($i) => new SeriesPointDTO('2026-01-02', "l{$i}", 1), range(1, 10)), $count, true],
    ];
});
