<?php

use App\Kernel\Contracts\PartyDirectory;
use App\Kernel\Dtos\CustomerCreated;
use App\Kernel\Dtos\CustomerUpdated;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Parties\Party;
use Tests\TestCase;

require_once __DIR__.'/CoreTestHelpers.php';
require_once __DIR__.'/CoreHttpHelpers.php';

uses( TestCase::class, DatabaseTransactions::class);

function custData(array $o = []): array
{
    return array_merge([
        'customer_name' => 'Jane Citizen', 'email' => 'Jane@Example.com', 'phone' => '+27 82 555 0100',
        'billing_address' => '5 Long Street, Cape Town', 'tax_number' => 'VAT 4123456789',
    ], $o);
}

beforeEach(function () {
    Mail::fake();
    [$this->idA, $this->adminA] = coreHttpTenant('alpha');
    $this->plainA = coreHttpPlainUser('alpha');
    [$this->idB, $this->adminB] = coreHttpTenant('beta');
    $this->post = fn (string $sub, $user, array $data) => $this->actingAs($user, 'workspace')->postJson(coreHost($sub, '/core/customers'), $data);
});

it('creates a customer (201 CustomerPayload), lowercases the email and dispatches CustomerCreated', function () {
    Event::fake([CustomerCreated::class, CustomerUpdated::class]);

    ($this->post)('alpha', $this->adminA, custData())->assertCreated()->assertJson([
        'customer_name' => 'Jane Citizen', 'email' => 'jane@example.com', 'phone' => '+27 82 555 0100',
    ])->assertJsonStructure(['customer_id']);

    expect(DB::table('core_parties')->where('tenant_id', $this->idA)->count())->toBe(1);
    Event::assertDispatched(CustomerCreated::class, fn ($e) => $e->tenantId() === $this->idA && $e->dto->email === 'jane@example.com' && $e->dto->taxNumber === 'VAT 4123456789');
    Event::assertNotDispatched(CustomerUpdated::class);
});

it('updates the existing record when the same email (any casing) is posted again: 200, one row, CustomerUpdated', function () {
    ($this->post)('alpha', $this->adminA, custData())->assertCreated();
    Event::fake([CustomerCreated::class, CustomerUpdated::class]);

    ($this->post)('alpha', $this->adminA, custData(['email' => 'JANE@EXAMPLE.COM', 'customer_name' => 'Jane Q Citizen']))
        ->assertOk()->assertJsonPath('customer_name', 'Jane Q Citizen');

    expect(DB::table('core_parties')->where('tenant_id', $this->idA)->count())->toBe(1);
    Event::assertDispatched(CustomerUpdated::class);
    Event::assertNotDispatched(CustomerCreated::class);
});

it('leaves fields you did not send unchanged, and clears fields you send empty', function () {
    ($this->post)('alpha', $this->adminA, custData())->assertCreated();

    ($this->post)('alpha', $this->adminA, ['customer_name' => 'Jane', 'email' => 'jane@example.com'])->assertOk()
        ->assertJsonPath('phone', '+27 82 555 0100');                    // absent key: kept

    ($this->post)('alpha', $this->adminA, ['customer_name' => 'Jane', 'email' => 'jane@example.com', 'phone' => ''])->assertOk()
        ->assertJsonPath('phone', null);                                  // explicit empty: cleared
});

it('validates name, email, phone and tax number (422 "Validation failed")', function (array $override, string $field) {
    ($this->post)('alpha', $this->adminA, custData($override))->assertStatus(422)
        ->assertJsonPath('message', 'Validation failed')->assertJsonStructure(['errors' => [$field]]);
    expect(DB::table('core_parties')->count())->toBe(0);
})->with([
    'no name' => [['customer_name' => ''], 'customer_name'],
    'bad email' => [['email' => 'nope'], 'email'],
    'bad phone' => [['phone' => 'ring me'], 'phone'],
    'bad tax' => [['tax_number' => '<x>'], 'tax_number'],
    'long address' => [['billing_address' => str_repeat('a', 501)], 'billing_address'],
]);

it('requires core.customers.manage (403) and a signed-in user (401)', function () {
    ($this->post)('alpha', $this->plainA, custData())->assertForbidden()->assertJsonPath('message', 'Missing permission to manage customers');
    coreAsGuest();
    $this->postJson(coreHost('alpha', '/core/customers'), custData())->assertUnauthorized();
    expect(DB::table('core_parties')->count())->toBe(0);
});

it('lets two tenants hold the same email independently', function () {
    ($this->post)('alpha', $this->adminA, custData())->assertCreated();
    ($this->post)('beta', $this->adminB, custData())->assertCreated();

    // RLS shows each tenant only its own row, so look as each tenant in turn.
    coreAsTenant($this->idA);
    expect(DB::table('core_parties')->where('email', 'jane@example.com')->count())->toBe(1);

    coreAsTenant($this->idB);
    expect(DB::table('core_parties')->where('email', 'jane@example.com')->count())->toBe(1);
});

it('enforces one party per email per tenant in the database too', function () {
    ($this->post)('alpha', $this->adminA, custData())->assertCreated();
    app(TenantContext::class)->set($this->idA);   // so RLS accepts the row and the UNIQUE index is what refuses it

    expect(fn () => coreInSavepoint(fn () => DB::table('core_parties')->insert([
        'tenant_id' => $this->idA, 'name' => 'Dup', 'email' => 'jane@example.com', 'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(\Illuminate\Database\UniqueConstraintViolationException::class);
});

it('keeps tenants apart: lists, updates and the directory never cross', function () {
    $id = ($this->post)('alpha', $this->adminA, custData())->assertCreated()->json('customer_id');

    $this->actingAs($this->adminB, 'workspace')->get(coreHost('beta', '/core/customers'))->assertInertia(
        fn ($p) => $p->component('Core/Customers')->has('customers.data', 0),
    );
    $this->actingAs($this->adminB, 'workspace')->putJson(coreHost('beta', "/core/customers/{$id}"), ['customer_name' => 'Hijack'])->assertNotFound();
    coreAsTenant($this->idA);
    expect(DB::table('core_parties')->where('id', $id)->value('name'))->toBe('Jane Citizen');

    app(TenantContext::class)->set($this->idB);
    expect(app(PartyDirectory::class)->find($id))->toBeNull()
        ->and(app(PartyDirectory::class)->findMany([$id]))->toBe([])
        ->and(Party::withoutGlobalScopes()->count())->toBe(0);   // RLS hides tenant A's row even without the app-level scope
});

it('changes an email by id, but refuses one already used by another record (409)', function () {
    $a = ($this->post)('alpha', $this->adminA, custData())->json('customer_id');
    ($this->post)('alpha', $this->adminA, custData(['email' => 'other@example.com', 'customer_name' => 'Other']))->assertCreated();
    $put = fn (int $id, array $d) => $this->actingAs($this->adminA, 'workspace')->putJson(coreHost('alpha', "/core/customers/{$id}"), $d);

    $put($a, ['email' => 'New@Example.com'])->assertOk()->assertJsonPath('email', 'new@example.com');
    $put($a, ['email' => 'OTHER@example.com'])->assertStatus(409);
    $put(999999, ['customer_name' => 'x'])->assertNotFound();
    $put($a, ['email' => 'bad'])->assertStatus(422);
});

it('exposes the directory to other modules as DTOs (the only way they read customer details)', function () {
    $id = ($this->post)('alpha', $this->adminA, custData())->json('customer_id');
    app(TenantContext::class)->set($this->idA);

    $dto = app(PartyDirectory::class)->find($id);

    expect($dto)->toBeInstanceOf(\App\Kernel\Dtos\CustomerDTO::class)->and($dto->customerId)->toBe($id)->and($dto->tenantId)->toBe($this->idA)
        ->and($dto->name)->toBe('Jane Citizen')->and(app(PartyDirectory::class)->findMany([$id, $id, 424242]))->toHaveCount(1);
});

it('lists and searches (wildcards are literal) and any signed-in user may view but not manage', function () {
    foreach (['Alice Smith' => 'alice@x.com', 'Bob Jones' => 'bob@x.com', '100% Pure' => 'pure@x.com'] as $n => $e) {
        ($this->post)('alpha', $this->adminA, custData(['customer_name' => $n, 'email' => $e]))->assertCreated();
    }

    $this->actingAs($this->plainA, 'workspace')->get(coreHost('alpha', '/core/customers'))->assertOk()->assertInertia(
        fn ($p) => $p->has('customers.data', 3)->where('canManage', false)->where('customers.data.0.customer_name', '100% Pure'),
    );
    $this->actingAs($this->adminA, 'workspace')->get(coreHost('alpha', '/core/customers?q=ALICE'))->assertInertia(
        fn ($p) => $p->has('customers.data', 1)->where('canManage', true),
    );
    $this->actingAs($this->adminA, 'workspace')->get(coreHost('alpha', '/core/customers?q='.urlencode('%')))->assertInertia(
        fn ($p) => $p->has('customers.data', 1)->where('customers.data.0.customer_name', '100% Pure'),   // "%" is not a match-all
    );
});

it('keeps the permission cache per tenant: alternating tenants never inherit each other\'s roles', function () {
    foreach (['alpha', 'beta', 'alpha', 'beta'] as $i => $sub) {
        $admin = $sub === 'alpha' ? $this->adminA : $this->adminB;
        ($this->post)($sub, $admin, custData(['email' => "u{$i}@example.com"]))->assertCreated();
    }
});
