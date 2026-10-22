<?php

use App\Kernel\Contracts\ExtensionPointRegistrar;
use App\Platform\Modules\ModuleManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Extensions\ExtensionPointRegistry;
use Modules\Core\Extensions\ExtensionResolver;
use Tests\TestCase;

require_once __DIR__.'/CoreTestHelpers.php';
require_once __DIR__.'/CoreHttpHelpers.php';

uses( TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    Mail::fake();
    [$this->idA, $this->adminA] = coreHttpTenant('alpha');
    [$this->idB, $this->adminB] = coreHttpTenant('beta');
    $this->hooks = app(ExtensionPointRegistrar::class);
    $this->hooks->register('CustomerProfileTabs', 'Support', 'Support/TicketsTab', 'Tickets', 50);
    $this->hooks->register('CustomerProfileTabs', 'Financial', 'Financial/InvoicesTab', 'Invoices', 40);
    $this->names = fn (string $tenantId) => collect((function () use ($tenantId) {
        app(\App\Tenancy\TenantContext::class)->set($tenantId);

        return app(ExtensionResolver::class)->forHook('CustomerProfileTabs');
    })())->pluck('component')->all();
});

it('publishes the Core hooks at boot, and the registrar IS the registry', function () {
    expect(app(ExtensionPointRegistry::class)->published())->toContain('CustomerProfileTabs', 'DashboardWidgets')
        ->and($this->hooks)->toBe(app(ExtensionPointRegistry::class));
});

it('refuses to register into a hook Core has not published', function () {
    expect(fn () => $this->hooks->register('SomeoneElsesScreen', 'Support', 'Support/X', 'X'))->toThrow(InvalidArgumentException::class);
});

it('renders every enabled module\'s components in order_index sequence (Core\'s own tab included)', function () {
    expect(($this->names)($this->idA))->toBe(['Financial/InvoicesTab', 'Support/TicketsTab', 'Core/ModuleDataTab']);
});

it('deregisters a module\'s components automatically when it is disabled for the tenant, and restores them on re-enable', function () {
    app(ModuleManager::class)->disable($this->idA, 'Support');

    expect(($this->names)($this->idA))->toBe(['Financial/InvoicesTab', 'Core/ModuleDataTab']);

    app(ModuleManager::class)->enable($this->idA, 'Support');

    expect(($this->names)($this->idA))->toBe(['Financial/InvoicesTab', 'Support/TicketsTab', 'Core/ModuleDataTab']);
});

it('only affects the tenant that disabled the module', function () {
    app(ModuleManager::class)->disable($this->idA, 'Support');

    expect(($this->names)($this->idB))->toContain('Support/TicketsTab');
});

it('shares the visible extensions as an Inertia prop for signed-in users only', function () {
    app(ModuleManager::class)->disable($this->idA, 'Financial');

    $this->actingAs($this->adminA, 'workspace')->get(coreHost('alpha', '/dashboard'))->assertInertia(
        fn ($p) => $p->where('extensions.CustomerProfileTabs.0.component', 'Support/TicketsTab')
            ->where('extensions.CustomerProfileTabs', fn ($l) => collect($l)->pluck('component')->doesntContain('Financial/InvoicesTab'))
            ->has('extensions.DashboardWidgets'),
    );

    auth('workspace')->logout();
    $this->get(coreHost('alpha', '/login'))->assertInertia(fn ($p) => $p->where('extensions', []));
});
