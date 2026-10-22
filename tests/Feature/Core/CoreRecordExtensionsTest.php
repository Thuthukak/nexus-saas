<?php

use App\Kernel\Contracts\RecordMetadataStore;
use App\Kernel\Dtos\RecordMetadataUpdated;
use App\Platform\Modules\ModuleManager;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Parties\Party;
use Modules\Core\Records\RecordNotFound;
use Tests\TestCase;

require_once __DIR__.'/CoreTestHelpers.php';
require_once __DIR__.'/CoreHttpHelpers.php';

uses( TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    Mail::fake();
    [$this->idA, $this->adminA] = coreHttpTenant('alpha');
    $this->plainA = coreHttpPlainUser('alpha');
    $this->party = Party::create(['name' => 'Jane', 'email' => 'jane@example.com'])->id;   // context is alpha here
    [$this->idB, $this->adminB] = coreHttpTenant('beta');

    $this->write = fn (string $module, mixed $metadata, ?string $header = null, string $entity = 'customers', ?string $id = null, $user = null) =>
        $this->actingAs($user ?? $this->adminA, 'workspace')->postJson(
            coreHost('alpha', "/core/records/{$entity}/".($id ?? $this->party).'/metadata'),
            ['module_key' => $module, 'metadata' => $metadata],
            ['X-Module-Key' => $header ?? $module],
        );
    $this->read = fn (string $module, ?string $header = null, $user = null) =>
        $this->actingAs($user ?? $this->adminA, 'workspace')->getJson(
            coreHost('alpha', "/core/records/customers/{$this->party}/metadata?module_key={$module}"), ['X-Module-Key' => $header ?? $module],
        );
    $this->stored = fn () => DB::table('record_extensions')->where('tenant_id', $this->idA)->where('core_entity_id', (string) $this->party);
});

it('lets a module write its own namespace on an open record (200 RecordMetadataPayload) and fires an event', function () {
    Event::fake([RecordMetadataUpdated::class]);

    ($this->write)('Support', ['tier' => 'gold', 'flags' => ['vip']])->assertOk()->assertJson([
        'core_entity' => 'customers', 'core_entity_id' => (string) $this->party, 'module_key' => 'Support', 'metadata' => ['tier' => 'gold'],
    ]);

    expect(($this->stored)()->where('module_key', 'Support')->count())->toBe(1);
    Event::assertDispatched(RecordMetadataUpdated::class, fn ($e) => $e->tenantId() === $this->idA && $e->dto->moduleKey === 'Support' && $e->dto->coreEntity === 'customers');
});

it('accepts metadata as a JSON string, as the spec describes', function () {
    ($this->write)('Support', '{"tier":"silver"}')->assertOk()->assertJsonPath('metadata.tier', 'silver');
});

it('replaces the whole namespace on each write', function () {
    ($this->write)('Support', ['a' => 1, 'b' => 2])->assertOk();
    ($this->write)('Support', ['c' => 3])->assertOk();

    ($this->read)('Support')->assertOk()->assertExactJson([
        'core_entity' => 'customers', 'core_entity_id' => (string) $this->party, 'module_key' => 'Support', 'metadata' => ['c' => 3],
    ]);
});

it('refuses to write another module\'s namespace (403) and stores nothing', function (?string $header) {
    ($this->write)('Financial', ['x' => 1], $header ?? '')->assertForbidden()
        ->assertJsonPath('message', "This module may not write to another module's metadata namespace");

    expect(($this->stored)()->count())->toBe(0);
})->with([['Support'], ['Core'], [null]]);

it('refuses a request with no module identity at all', function () {
    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', "/core/records/customers/{$this->party}/metadata"), ['module_key' => 'Support', 'metadata' => ['a' => 1]])
        ->assertForbidden();
});

it('keeps modules\' namespaces apart: each reads only its own', function () {
    ($this->write)('Support', ['secret' => 'support-only'])->assertOk();
    ($this->write)('Financial', ['credit_limit' => 5000])->assertOk();

    ($this->read)('Financial')->assertOk()->assertJsonPath('metadata', ['credit_limit' => 5000])->assertJsonMissing(['secret' => 'support-only']);
    ($this->read)('Financial', 'Support')->assertForbidden();   // reading Financial's namespace while identifying as Support
});

it('returns null metadata when the module has stored nothing', function () {
    ($this->read)('Support')->assertOk()->assertJsonPath('metadata', null);
});

it('refuses a module that is disabled for the tenant', function () {
    app(ModuleManager::class)->disable($this->idA, 'Support');

    ($this->write)('Support', ['a' => 1])->assertForbidden()->assertJsonPath('message', 'This module is not enabled for this workspace');
    ($this->read)('Support')->assertForbidden();
});

it('returns 404 "Core record not found" for entities that are not open and records that do not exist', function (string $entity, string $id) {
    ($this->write)('Support', ['a' => 1], null, $entity, $id)->assertNotFound()->assertJsonPath('message', 'Core record not found');
})->with([['users', '1'], ['core_parties', '1'], ['customers', '999999'], ['customers', 'abc'], ['customers', '1x']]);

it('requires permission to edit the record being extended (403), but any signed-in user may read', function () {
    ($this->write)('Support', ['a' => 1], null, 'customers', null, $this->plainA)->assertForbidden()->assertJsonPath('message', 'Missing permission to extend this record');
    expect(($this->stored)()->count())->toBe(0);

    ($this->write)('Support', ['a' => 1])->assertOk();
    ($this->read)('Support', null, $this->plainA)->assertOk()->assertJsonPath('metadata.a', 1);
});

it('validates the metadata (422): must be a bounded JSON object', function (mixed $bad) {
    ($this->write)('Support', $bad)->assertStatus(422)->assertJsonPath('message', 'Validation failed')->assertJsonStructure(['errors' => ['metadata']]);
    expect(($this->stored)()->count())->toBe(0);
})->with([['[1,2,3]'], ['"just a string"'], ['nope'], [[1, 2, 3]], [null], [str_repeat('a', 70000)]]);

it('never lets a tenant touch another tenant\'s record', function () {
    $this->actingAs($this->adminB, 'workspace')->postJson(coreHost('beta', "/core/records/customers/{$this->party}/metadata"),
        ['module_key' => 'Support', 'metadata' => ['hijack' => true]], ['X-Module-Key' => 'Support'])->assertNotFound();

    expect(DB::table('record_extensions')->count())->toBe(0);

    ($this->write)('Support', ['mine' => 1])->assertOk();   // a legitimate write for alpha
    app(TenantContext::class)->set($this->idB);
    expect(DB::table('record_extensions')->count())->toBe(0);   // RLS: alpha's row is invisible from beta
});

it('enforces "a JSON object" in the database as well', function () {
    app(TenantContext::class)->set($this->idA);

    expect(fn () => coreInSavepoint(fn () => DB::statement(
        "INSERT INTO record_extensions (tenant_id, core_entity, core_entity_id, module_key, metadata, created_at, updated_at) VALUES (?, 'customers', '1', 'Support', '[1]'::jsonb, now(), now())",
        [$this->idA],
    )))->toThrow(\Illuminate\Database\QueryException::class);
});

// ---- the PHP capability handle other modules use ----

it('hands a module a handle bound to its own namespace only', function () {
    app(TenantContext::class)->set($this->idA);
    $support = app(RecordMetadataStore::class)->forModule('Support');
    $financial = app(RecordMetadataStore::class)->forModule('Financial');

    $support->write('customers', (string) $this->party, ['tier' => 'gold']);
    $financial->write('customers', (string) $this->party, ['limit' => 10]);

    expect($support->read('customers', (string) $this->party))->toBe(['tier' => 'gold'])
        ->and($financial->read('customers', (string) $this->party))->toBe(['limit' => 10])
        ->and((new ReflectionMethod($support, 'read'))->getNumberOfParameters())->toBe(2);   // no module parameter to abuse
});

it('rejects unknown or disabled modules and unopened or missing records on the handle', function () {
    app(TenantContext::class)->set($this->idA);
    $store = app(RecordMetadataStore::class);

    expect(fn () => $store->forModule('Ghost'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $store->forModule('Support')->write('users', '1', ['a' => 1]))->toThrow(RecordNotFound::class);
    expect(fn () => $store->forModule('Support')->write('customers', '424242', ['a' => 1]))->toThrow(RecordNotFound::class);

    $handle = $store->forModule('Support');
    app(ModuleManager::class)->disable($this->idA, 'Support');
    expect(fn () => $handle->write('customers', (string) $this->party, ['a' => 1]))->toThrow(InvalidArgumentException::class);   // handle outlived the module
});

// ---- merged view on the profile ----

it('shows the profile with every ENABLED module\'s namespace, hides a disabled one but keeps its data', function () {
    ($this->write)('Support', ['tier' => 'gold'])->assertOk();
    ($this->write)('Financial', ['limit' => 10])->assertOk();
    $profile = fn () => $this->actingAs($this->adminA, 'workspace')->get(coreHost('alpha', "/core/customers/{$this->party}"));

    $profile()->assertOk()->assertInertia(fn ($p) => $p->component('Core/CustomerProfile')->where('customer.customer_name', 'Jane')
        ->where('metadata.Support.tier', 'gold')->where('metadata.Financial.limit', 10));

    app(ModuleManager::class)->disable($this->idA, 'Support');
    $profile()->assertInertia(fn ($p) => $p->where('metadata', fn ($m) => ! isset($m['Support']) && isset($m['Financial'])));
    expect(($this->stored)()->where('module_key', 'Support')->count())->toBe(1);   // preserved

    app(ModuleManager::class)->enable($this->idA, 'Support');
    $profile()->assertInertia(fn ($p) => $p->where('metadata.Support.tier', 'gold'));
});

it('404s a customer profile from another tenant', function () {
    $this->actingAs($this->adminB, 'workspace')->get(coreHost('beta', "/core/customers/{$this->party}"))->assertNotFound();
});
