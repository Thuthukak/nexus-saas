<?php

use App\Platform\Modules\DependencyGraph;
use App\Platform\Modules\ModuleDependencyRegistry;
use App\Platform\Modules\ModuleException;
use App\Platform\Modules\ModuleManager;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

require_once __DIR__.'/CoreTestHelpers.php';
require_once __DIR__.'/CoreHttpHelpers.php';

uses( TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    Mail::fake();
    $this->registry = app(ModuleDependencyRegistry::class);
    coreMakeTenant();                                          // makes sure the plan catalogue exists
    $this->registry->sync(config('module-dependencies'));     // known starting point
    $this->rows = fn () => DB::table('module_dependencies')->orderBy('module_key')->orderBy('requires_key')->get(['module_key', 'requires_key'])->map(fn ($r) => (array) $r)->all();
});

it('ships a valid dependency declaration in config', function () {
    DependencyGraph::validate(config('module-dependencies'));

    expect(config('module-dependencies.Core'))->toBe([])->and(config('module-dependencies.Support'))->toContain('Core');
});

it('syncs the config to the table, idempotently', function () {
    $this->registry->sync(config('module-dependencies'));
    $this->registry->sync(config('module-dependencies'));

    expect(($this->rows)())->toBe([['module_key' => 'Financial', 'requires_key' => 'Core'], ['module_key' => 'Support', 'requires_key' => 'Core']]);
});

it('exposes a declared prerequisite to ModuleManager, which then enforces it', function () {
    [$id] = coreHttpTenant('alpha');
    $mm = app(ModuleManager::class);
    $mm->disable($id, 'Support');
    $mm->disable($id, 'Financial');

    $this->registry->declare('Support', ['Core', 'Financial']);

    expect(DependencyGraph::fromDatabase()->requiresOf('Support'))->toContain('Core', 'Financial');
    expect(fn () => $mm->enable($id, 'Support'))->toThrow(ModuleException::class, 'prerequisites');
    $mm->enable($id, 'Financial');
    $mm->enable($id, 'Support');
    expect($mm->activeModules($id))->toContain('Support');
    expect(fn () => $mm->disable($id, 'Financial'))->toThrow(ModuleException::class, 'dependents');
});

it('rejects invalid declarations and changes nothing', function (string $module, array $requires) {
    $before = ($this->rows)();

    expect(fn () => $this->registry->declare($module, $requires))->toThrow(InvalidArgumentException::class);

    expect(($this->rows)())->toBe($before);
})->with([
    'Core must stay empty' => ['Core', ['Financial']],
    'non-Core must require Core' => ['Financial', []],
    'non-Core must require Core (other module only)' => ['Support', ['Financial']],
    'unknown prerequisite' => ['Support', ['Core', 'Ghost']],
    'unknown module' => ['Ghost', ['Core']],
    'self dependency' => ['Financial', ['Core', 'Financial']],
]);

it('rejects a circular requirement between two modules', function () {
    $this->registry->declare('Financial', ['Core', 'Support']);
    $before = ($this->rows)();

    expect(fn () => $this->registry->declare('Support', ['Core', 'Financial']))->toThrow(InvalidArgumentException::class, 'Circular');

    expect(($this->rows)())->toBe($before);
});

it('refuses a new prerequisite that would strand a tenant already running the module without it', function () {
    [$id] = coreHttpTenant('alpha');
    app(ModuleManager::class)->disable($id, 'Financial');   // Support stays on, Financial off
    $before = ($this->rows)();

    try {
        $this->registry->declare('Support', ['Core', 'Financial']);
        $this->fail('expected a ModuleException');
    } catch (ModuleException $e) {
        expect($e->reason)->toBe('strands_tenants')->and($e->getMessage())->toContain('tenant(s)');
    }

    expect(($this->rows)())->toBe($before);
});

it('has a database check against self-dependency', function () {
    expect(fn () => coreInSavepoint(fn () => DB::table('module_dependencies')->insert(['module_key' => 'Support', 'requires_key' => 'Support'])))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('syncs from the command, and a broken config fails without touching the table', function () {
    $this->artisan('modules:sync-dependencies')->assertSuccessful();

    $before = ($this->rows)();
    config(['module-dependencies' => ['Core' => [], 'Financial' => [], 'Support' => ['Core']]]);   // Financial lost its Core prerequisite

    $this->artisan('modules:sync-dependencies')->assertFailed();
    expect(($this->rows)())->toBe($before);
});
