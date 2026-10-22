<?php

use App\Kernel\Dtos\ModuleStateChanged;
use App\Platform\Modules\ModuleException;
use App\Platform\Modules\ModuleManager;
use App\Tenancy\Tenant;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;

uses( TestCase::class, DatabaseTransactions::class);

/** Run the seeder only if the catalogue is empty (migrations run as owner; tests as app role). */
beforeEach(function () {
    if (DB::table('plans')->count() === 0) {
        $this->seed(PlanCatalogueSeeder::class);
    }

    $this->tenant = Tenant::create([
        'name' => 'Acme', 'subdomain' => 'acme-'.Str::lower(Str::random(6)),
        'country_code' => 'ZA', 'billing_currency' => 'ZAR',
    ]);
    $this->id = $this->tenant->id;

    DB::table('tenant_subscriptions')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->id,
        'plan_id' => DB::table('plans')->where('key', 'starter')->value('id'),
        'billing_cycle' => 'monthly', 'currency' => 'ZAR', 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('tenant_modules')->insert([
        'tenant_id' => $this->id, 'module_key' => 'Core', 'is_enabled' => true,
        'first_enabled_at' => now(), 'enabled_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->manager = app(ModuleManager::class);
});

it('never lets Core be disabled', function () {
    expect(fn () => $this->manager->disable($this->id, 'Core'))
        ->toThrow(ModuleException::class, 'mandatory');
});

it('enables an included module whose prerequisites are met and fires an event', function () {
    Event::fake([ModuleStateChanged::class]);

    $this->manager->enable($this->id, 'Financial');

    expect($this->manager->activeModules($this->id))->toBe(['Core', 'Financial']);
    Event::assertDispatched(ModuleStateChanged::class, fn ($e) => $e->dto->moduleKey === 'Financial' && $e->dto->enabled && $e->dto->firstTimeEnabled);
});

it('is idempotent when enabling twice and flags first-time only once', function () {
    $this->manager->enable($this->id, 'Support');
    $this->manager->disable($this->id, 'Support');

    Event::fake([ModuleStateChanged::class]);
    $this->manager->enable($this->id, 'Support');

    Event::assertDispatched(ModuleStateChanged::class, fn ($e) => $e->dto->enabled && ! $e->dto->firstTimeEnabled);
});

it('rejects a module that is neither in the plan nor an add-on, then allows it as an add-on', function () {
    DB::table('plan_modules')->where('module_key', 'Support')->delete(); // simulate a plan without Support

    expect(fn () => $this->manager->enable($this->id, 'Support'))
        ->toThrow(ModuleException::class, 'not included');

    DB::table('tenant_addons')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->id, 'module_key' => 'Support',
        'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->manager->enable($this->id, 'Support');
    expect($this->manager->activeModules($this->id))->toContain('Support');
});

it('blocks enabling without prerequisites and disabling while a dependent is enabled', function () {
    // Declare a test-only dependency: Support requires Financial (inside this rolled-back transaction).
    DB::table('module_dependencies')->insert(['module_key' => 'Support', 'requires_key' => 'Financial']);

    expect(fn () => $this->manager->enable($this->id, 'Support'))
        ->toThrow(ModuleException::class, 'prerequisites');

    $this->manager->enable($this->id, 'Financial');
    $this->manager->enable($this->id, 'Support');

    expect(fn () => $this->manager->disable($this->id, 'Financial'))
        ->toThrow(ModuleException::class, 'dependents');
});

it('keeps module data when a module is disabled', function () {
    $this->manager->enable($this->id, 'Financial');
    $this->manager->disable($this->id, 'Financial');

    $row = DB::table('tenant_modules')->where('tenant_id', $this->id)->where('module_key', 'Financial')->first();
    expect((bool) $row->is_enabled)->toBeFalse()
        ->and($row->first_enabled_at)->not->toBeNull();
});

it('seeds the exact catalogue from the spec', function () {
    $price = fn (string $plan, string $cur, string $cycle) => DB::table('plan_prices')
        ->join('plans', 'plans.id', '=', 'plan_prices.plan_id')
        ->where(['plans.key' => $plan, 'currency' => $cur, 'cycle' => $cycle])->first();

    expect($price('starter', 'ZAR', 'monthly')->charge_minor)->toBe(24_900)
        ->and($price('starter', 'ZAR', 'annual')->monthly_equivalent_minor)->toBe(19_900)
        ->and($price('starter', 'ZAR', 'annual')->charge_minor)->toBe(238_800)
        ->and($price('enterprise', 'USD', 'annual')->monthly_equivalent_minor)->toBe(11_900)
        ->and((bool) $price('enterprise', 'USD', 'monthly')->is_starting_from)->toBeTrue();

    $limits = DB::table('plan_limits')->join('plans', 'plans.id', '=', 'plan_limits.plan_id');
    $pro = (clone $limits)->where(['plans.key' => 'professional', 'currency' => 'USD'])->first();
    $ent = (clone $limits)->where(['plans.key' => 'enterprise', 'currency' => 'ZAR'])->first();

    expect($pro->seats)->toBe(10)->and($pro->tickets_per_month)->toBe(500)
        ->and($pro->volume_per_month_minor)->toBe(3_000_000)
        ->and((bool) $pro->custom_domain_allowed)->toBeFalse()
        ->and($ent->seats)->toBeNull()->and((bool) $ent->custom_domain_allowed)->toBeTrue();
});
