<?php

use App\Kernel\Dtos\SettingsUpdated;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Core\Facades\Settings;
use Modules\Core\Settings\SettingRegistry;
use Modules\Core\Settings\SettingsException;
use Tests\TestCase;

require_once __DIR__.'/CoreTestHelpers.php';

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    $r = app(SettingRegistry::class);
    $r->register('tenant', 'greeting', 'string', 'Hello');
    $r->register('tenant', 'max_items', 'int', 10);
    $r->register('Financial', 'invoice_prefix', 'string', 'INV');
    Cache::flush();
    $this->a = coreMakeTenant();
    $this->b = coreMakeTenant();
});

it('returns the registered default until a value is stored', function () {
    coreActAs($this->a);

    expect(Settings::get('tenant', 'greeting'))->toBe('Hello')->and(Settings::get('Financial', 'invoice_prefix'))->toBe('INV');
});

it('stores a JSON-typed value and the next read sees it (cache cleared on write)', function () {
    coreActAs($this->a);

    expect(Settings::get('tenant', 'max_items'))->toBe(10);   // primes the cache
    Settings::set('tenant', 'max_items', 25);

    expect(Settings::get('tenant', 'max_items'))->toBe(25);
    expect(DB::table('tenant_settings')->where('key', 'max_items')->value('value'))->toBe('25');
});

it('namespaces module settings away from tenant-wide ones', function () {
    coreActAs($this->a);
    Settings::set('Financial', 'invoice_prefix', 'ACME');

    expect(Settings::get('Financial', 'invoice_prefix'))->toBe('ACME')->and(Settings::get('tenant', 'greeting'))->toBe('Hello');
});

it('rejects unregistered keys, wrong types and unknown scopes', function () {
    coreActAs($this->a);

    expect(fn () => Settings::set('tenant', 'nope', 'x'))->toThrow(SettingsException::class);
    expect(fn () => Settings::set('tenant', 'max_items', '25'))->toThrow(SettingsException::class);
    expect(fn () => Settings::set('Bogus', 'greeting', 'x'))->toThrow(SettingsException::class);
    expect(DB::table('tenant_settings')->count())->toBe(0);
});

it('writes all-or-nothing: one invalid value stores none', function () {
    coreActAs($this->a);

    expect(fn () => Settings::setMany('tenant', ['greeting' => 'Hi', 'max_items' => 'not-an-int']))->toThrow(SettingsException::class);

    expect(Settings::get('tenant', 'greeting'))->toBe('Hello');
});

it('never leaks settings or cache entries across tenants', function () {
    coreActAs($this->a);
    Settings::set('tenant', 'greeting', 'A-only');
    expect(Settings::get('tenant', 'greeting'))->toBe('A-only');   // A's scope is now cached

    coreActAs($this->b);
    expect(Settings::get('tenant', 'greeting'))->toBe('Hello');

    coreActAs($this->a);
    expect(Settings::get('tenant', 'greeting'))->toBe('A-only');
});

it('fails closed without a tenant context', function () {
    app(\App\Tenancy\TenantContext::class)->clear();

    expect(fn () => Settings::get('tenant', 'greeting'))->toThrow(\RuntimeException::class);
});

it('broadcasts SettingsUpdated with a typed DTO for each changed key', function () {
    Event::fake([SettingsUpdated::class]);
    coreActAs($this->a);

    Settings::setMany('tenant', ['greeting' => 'Hi', 'max_items' => 3]);

    Event::assertDispatched(SettingsUpdated::class, fn ($e) => $e->tenantId() === $this->a->id
        && $e->dto->scope === 'tenant' && $e->dto->key === 'greeting' && $e->dto->valueJson === '"Hi"');
    Event::assertDispatched(SettingsUpdated::class, fn ($e) => $e->dto->key === 'max_items' && $e->dto->valueJson === '3');
});
