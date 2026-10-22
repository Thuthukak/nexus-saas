<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Modules\Core\Models\User;
use Modules\Core\Permissions\OperatorAuthority;
use Modules\Core\Permissions\PermissionException;
use Modules\Core\Permissions\PermissionManager;
use Modules\Core\Services\CoreTenantSeeder;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

require_once __DIR__.'/CoreTestHelpers.php';

uses(TestCase::class, DatabaseTransactions::class);

beforeEach(function () {
    $this->tenant = coreMakeTenant();
    app(CoreTenantSeeder::class)->seed($this->tenant->id);
    coreActAs($this->tenant);
    $this->perms = app(PermissionManager::class);
    $this->operator = app(OperatorAuthority::class);
});

function coreMakeUser(string $email): User
{
    return User::create(['name' => 'U', 'email' => $email, 'password' => 'secret-pass-1']);
}

it('seeds a protected Super Admin and a Tenant Admin role for the new tenant', function () {
    expect(Role::pluck('name')->sort()->values()->all())->toBe(['Super Admin', 'Tenant Admin'])
        ->and(Role::findByName('Super Admin', 'workspace')->hasPermissionTo('*'))->toBeTrue()
        ->and(Role::findByName('Tenant Admin', 'workspace')->hasPermissionTo('core.users.manage'))->toBeTrue();
});

it('rejects permission keys that break the naming convention', function () {
    $this->perms->upsertRole('Clerk');

    expect(fn () => $this->perms->attach('Clerk', 'invoices.create'))->toThrow(PermissionException::class)
        ->and(fn () => $this->perms->attach('Clerk', '*'))->toThrow(PermissionException::class);
});

it('attaches and detaches a permission on a tenant role', function () {
    $this->perms->upsertRole('Clerk');
    $this->perms->attach('Clerk', 'financial.invoices.create');
    expect(Role::findByName('Clerk', 'workspace')->hasPermissionTo('financial.invoices.create'))->toBeTrue();

    $this->perms->detach('Clerk', 'financial.invoices.create');
    expect(Role::findByName('Clerk', 'workspace')->fresh()->hasPermissionTo('financial.invoices.create'))->toBeFalse();
});

// ---- Super Admin guardrails: manager, policy AND database ----

it('blocks Super Admin edits through the permission manager', function () {
    expect(fn () => $this->perms->detach('Super Admin', 'core.users.manage'))->toThrow(PermissionException::class)
        ->and(fn () => $this->perms->attach('Super Admin', 'core.users.manage'))->toThrow(PermissionException::class)
        ->and(fn () => $this->perms->deleteRole('Super Admin'))->toThrow(PermissionException::class)
        ->and(fn () => $this->perms->upsertRole('Super Admin'))->toThrow(PermissionException::class);
});

it('blocks every tenant-level path to Super Admin at the database, even when going around the manager', function () {
    $user = coreMakeUser('victim@example.com');
    $super = Role::findByName('Super Admin', 'workspace');
    $this->perms->upsertRole('Clerk');

    // assign: Spatie call, then a raw insert
    expect(fn () => coreInSavepoint(fn () => $user->assignRole('Super Admin')))->toThrow(QueryException::class, 'platform operator');
    expect(fn () => coreInSavepoint(fn () => DB::table('model_has_roles')->insert([
        'role_id' => $super->id, 'model_type' => User::class, 'model_id' => $user->id, 'tenant_id' => $this->tenant->id,
    ])))->toThrow(QueryException::class, 'platform operator');

    // edit and delete the role itself
    expect(fn () => coreInSavepoint(fn () => $super->update(['name' => 'Boss'])))->toThrow(QueryException::class, 'cannot be edited');
    expect(fn () => coreInSavepoint(fn () => DB::table('roles')->where('id', $super->id)->delete()))->toThrow(QueryException::class, 'cannot be deleted');
    expect(fn () => coreInSavepoint(fn () => Role::findByName('Clerk', 'workspace')->update(['name' => 'Super Admin'])))
        ->toThrow(QueryException::class, 'cannot be edited');

    // strip the wildcard
    expect(fn () => coreInSavepoint(fn () => $super->revokePermissionTo('*')))->toThrow(QueryException::class, 'wildcard');

    // and nothing changed
    expect($user->fresh()->hasRole('Super Admin'))->toBeFalse()
        ->and(Role::findByName('Super Admin', 'workspace')->hasPermissionTo('*'))->toBeTrue();
});

it('lets only the platform operator assign and remove Super Admin, and the permission ends with the call', function () {
    $user = coreMakeUser('ops@example.com');

    $this->operator->run(fn () => $user->assignRole('Super Admin'));
    expect($user->fresh()->hasRole('Super Admin'))->toBeTrue();

    // outside run(): tenant level is blocked again
    expect(fn () => coreInSavepoint(fn () => $user->removeRole('Super Admin')))->toThrow(QueryException::class, 'platform operator');

    $this->operator->run(fn () => $user->removeRole('Super Admin'));
    expect($user->fresh()->hasRole('Super Admin'))->toBeFalse();
});

it('denies Super Admin role management via policy, even to a Super Admin user, but allows ordinary roles', function () {
    $this->perms->upsertRole('Clerk');
    $superRole = Role::findByName('Super Admin', 'workspace');
    $clerkRole = Role::findByName('Clerk', 'workspace');

    $super = coreMakeUser('super@example.com');
    $this->operator->run(fn () => $super->assignRole('Super Admin'));
    $admin = coreMakeUser('admin@example.com');
    $admin->assignRole('Tenant Admin');
    $nobody = coreMakeUser('nobody@example.com');

    foreach (['update', 'delete', 'assign'] as $ability) {
        expect(Gate::forUser($super)->denies($ability, $superRole))->toBeTrue("$ability Super Admin (as Super Admin)")
            ->and(Gate::forUser($admin)->denies($ability, $superRole))->toBeTrue("$ability Super Admin (as Tenant Admin)")
            ->and(Gate::forUser($admin)->allows($ability, $clerkRole))->toBeTrue("$ability ordinary role (as Tenant Admin)")
            ->and(Gate::forUser($nobody)->denies($ability, $clerkRole))->toBeTrue("$ability ordinary role (no permission)");
    }
});

it('gives Super Admin every ability via Gate::before, including permissions created later', function () {
    $super = coreMakeUser('s@example.com');
    $this->operator->run(fn () => $super->assignRole('Super Admin'));
    $admin = coreMakeUser('a@example.com');
    $admin->assignRole('Tenant Admin');

    // 'brand.new.permission' exists nowhere: Super Admin still passes, Tenant Admin does not.
    expect(Gate::forUser($super)->allows('financial.invoices.create'))->toBeTrue()
        ->and(Gate::forUser($super)->allows('brand.new.permission'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('core.users.manage'))->toBeTrue()
        ->and(Gate::forUser($admin)->allows('financial.invoices.create'))->toBeFalse();
});

it('keeps roles invisible to other tenants (RLS) so a Tenant Admin role never applies across tenants', function () {
    $this->perms->upsertRole('Only In A');

    $other = coreMakeTenant();
    app(CoreTenantSeeder::class)->seed($other->id);
    coreActAs($other);

    expect(Role::pluck('name')->all())->not->toContain('Only In A')
        // Raw query, no Eloquent: the database itself refuses the other tenant's rows.
        ->and(DB::table('roles')->where('name', 'Only In A')->count())->toBe(0);
});
