<?php

use App\Kernel\Contracts\DashboardWidgetRegistrar;
use App\Platform\Modules\ModuleManager;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Parties\Party;
use Tests\TestCase;

require_once __DIR__.'/CoreTestHelpers.php';
require_once __DIR__.'/CoreHttpHelpers.php';

uses( TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    Mail::fake();
    [$this->idA, $this->adminA] = coreHttpTenant('alpha');
    [$this->idB, $this->adminB] = coreHttpTenant('beta');
    $this->plainA = (function () { app(TenantContext::class)->set($this->idA); return coreHttpPlainUser('alpha'); })->call($this);
    app(\Modules\Core\Permissions\PermissionManager::class)->attach('Tenant Admin', 'financial.reports.view');
    $this->adminA = $this->adminA->fresh();
    $this->registrar = app(DashboardWidgetRegistrar::class);
    $this->registrar->register('Support', 'open-tickets', 'Open tickets', '/support/widgets/open-tickets', 0, 3, 4, 2);
    $this->registrar->register('Financial', 'revenue', 'Revenue', '/financial/widgets/revenue', 4, 3, 4, 2, permission: 'financial.reports.view');
    $this->ids = function (string $host, $user) {
        $r = $this->actingAs($user, 'workspace')->getJson(coreHost($host, '/core/dashboard'))->assertOk();
        auth('workspace')->logout();

        return collect($r->json('widgets'))->pluck('id')->all();
    };
});

it('assembles Core widgets plus every enabled module\'s widgets, with counts and grid positions', function () {
    $r = $this->actingAs($this->adminA, 'workspace')->getJson(coreHost('alpha', '/core/dashboard'))->assertOk();

    expect($r->json('widget_count'))->toBe(4)
        ->and(collect($r->json('widgets'))->pluck('id')->sort()->values()->all())->toBe(['core.customers', 'core.customers-trend', 'financial.revenue', 'support.open-tickets']);
    $r->assertJsonPath('widgets.0.grid', fn ($g) => isset($g['x'], $g['y'], $g['w'], $g['h']));
});

it('returns layout and endpoints only, never widget data', function () {
    $w = $this->actingAs($this->adminA, 'workspace')->getJson(coreHost('alpha', '/core/dashboard'))->json('widgets.0');

    expect(array_keys($w))->toEqualCanonicalizing(['id', 'module_key', 'title', 'data_endpoint', 'grid']);
});

it('drops a module\'s widgets when it is disabled for that tenant only, and restores them on re-enable', function () {
    app(ModuleManager::class)->disable($this->idA, 'Support');

    expect(($this->ids)('alpha', $this->adminA))->not->toContain('support.open-tickets')
        ->and(($this->ids)('beta', $this->adminB))->toContain('support.open-tickets');

    app(ModuleManager::class)->enable($this->idA, 'Support');

    expect(($this->ids)('alpha', $this->adminA))->toContain('support.open-tickets');
});

it('hides permission-gated widgets from users without the permission', function () {
    expect(($this->ids)('alpha', $this->plainA))->not->toContain('financial.revenue')->toContain('core.customers')
        ->and(($this->ids)('alpha', $this->adminA))->toContain('financial.revenue');
});

it('never overlaps widgets on the grid', function () {
    $this->registrar->register('Support', 'clash', 'Clash', '/support/widgets/clash', 0, 3, 8, 2);
    $widgets = $this->actingAs($this->adminA, 'workspace')->getJson(coreHost('alpha', '/core/dashboard'))->json('widgets');

    foreach ($widgets as $i => $p) {
        foreach ($widgets as $j => $q) {
            if ($i < $j) {
                $a = $p['grid'];
                $b = $q['grid'];
                expect($a['x'] < $b['x'] + $b['w'] && $b['x'] < $a['x'] + $a['w'] && $a['y'] < $b['y'] + $b['h'] && $b['y'] < $a['y'] + $a['h'])->toBeFalse();
            }
        }
    }
});

it('requires a signed-in workspace user', function () {
    $this->getJson(coreHost('alpha', '/core/dashboard'))->assertUnauthorized();
});

it('renders /dashboard with the widgets prop and keeps the route name', function () {
    $this->actingAs($this->adminA, 'workspace')->get(coreHost('alpha', '/dashboard'))
        ->assertOk()->assertInertia(fn ($p) => $p->component('Dashboard')->has('modules')->has('widgets', 4));

    expect(route('dashboard', [], false))->toBe('/dashboard');
});

it('serves the Core customers stat from the tenant\'s own data and vocabulary', function () {
    app(TenantContext::class)->set($this->idA);
    Party::create(['name' => 'One', 'email' => 'one@x.test']);
    Party::create(['name' => 'Two', 'email' => 'two@x.test']);
    app(TenantContext::class)->set($this->idB);
    Party::create(['name' => 'Other', 'email' => 'o@y.test']);

    $a = $this->actingAs($this->adminA, 'workspace')->getJson(coreHost('alpha', '/core/dashboard/widgets/customers'))->assertOk();
    expect($a->json('type'))->toBe('stat')->and($a->json('value'))->toBe('2');
});

it('serves the customers trend and fills the summary the first time it is viewed', function () {
    app(TenantContext::class)->set($this->idA);
    Party::create(['name' => 'One', 'email' => 'one@x.test']);

    $r = $this->actingAs($this->adminA, 'workspace')->getJson(coreHost('alpha', '/core/dashboard/widgets/customers-trend'))->assertOk();

    expect($r->json('type'))->toBe('series')
        ->and(collect($r->json('points'))->sum('value'))->toBe(1);
});
