<?php

use App\Tenancy\Rls;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantDomain;
use Tests\TestCase;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| These tests need PostgreSQL and a NON-superuser connection (nexus_saas),
| otherwise RLS is bypassed and the isolation tests are meaningless.
*/
uses(TestCase::class, DatabaseTransactions::class);

function makeTenant(string $sub): Tenant
{
    return Tenant::create([
        'name' => ucfirst($sub),
        'subdomain' => $sub,
        'country_code' => 'ZA',
        'billing_currency' => 'ZAR',
    ]);
}

beforeEach(function () {
    expect(DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user'))
        ->rolsuper->toBeFalse()
        ->rolbypassrls->toBeFalse();

    // Fixture table: created once, as the owner, and left in place between runs.
    $owner = Schema::connection('pgsql_migrate');

    $hasPolicy = DB::connection('pgsql_migrate')->selectOne(
    "select 1 as ok from pg_policies where tablename = 'rls_probe' and policyname = 'tenant_isolation'"
    );

    if (! $owner->hasTable('rls_probe') || ! $hasPolicy) {
        $owner->dropIfExists('rls_probe');
        $owner->create('rls_probe', function ($t) {
            $t->uuid('id')->primary();
            $t->uuid('tenant_id');
            $t->string('label');
        });

        // Rls::enable() uses the default connection, so point it at the owner briefly.
        $previous = DB::getDefaultConnection();
        DB::setDefaultConnection('pgsql_migrate');
        try {
            Rls::enable('rls_probe');
        } finally {
            DB::setDefaultConnection($previous);
        }
    }
});

it('returns no rows when no tenant is set (fails closed)', function () {
    DB::select("select set_config('app.current_tenant_id', ?, true)", [(string) Str::uuid()]);
    DB::table('rls_probe')->insert(['id' => Str::uuid(), 'tenant_id' => DB::selectOne("select current_setting('app.current_tenant_id') as v")->v, 'label' => 'x']);

    DB::select("select set_config('app.current_tenant_id', '', true)");

    expect(DB::table('rls_probe')->count())->toBe(0);
});

it('isolates tenants even for raw DB::table queries', function () {
    $a = (string) Str::uuid();
    $b = (string) Str::uuid();

    DB::select("select set_config('app.current_tenant_id', ?, true)", [$a]);
    DB::table('rls_probe')->insert(['id' => Str::uuid(), 'tenant_id' => $a, 'label' => 'A']);

    DB::select("select set_config('app.current_tenant_id', ?, true)", [$b]);
    DB::table('rls_probe')->insert(['id' => Str::uuid(), 'tenant_id' => $b, 'label' => 'B']);

    expect(DB::table('rls_probe')->pluck('label')->all())->toBe(['B']);

    DB::select("select set_config('app.current_tenant_id', ?, true)", [$a]);
    expect(DB::table('rls_probe')->pluck('label')->all())->toBe(['A']);
});

it('refuses to write a row for another tenant (WITH CHECK)', function () {
    $a = (string) Str::uuid();
    DB::select("select set_config('app.current_tenant_id', ?, true)", [$a]);

    expect(fn () => DB::table('rls_probe')->insert([
        'id' => Str::uuid(), 'tenant_id' => (string) Str::uuid(), 'label' => 'leak',
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('TenantContext pushes the id to the connection', function () {
    $id = (string) Str::uuid();
    app(TenantContext::class)->set($id);

    expect(DB::selectOne("select current_setting('app.current_tenant_id') as v")->v)->toBe($id);
});

it('resolves subdomains but never reserved names', function () {
    $root = config('tenancy.root_domain');
    expect($root)->toBeString()->not->toBeEmpty();

    makeTenant('acme');
    makeTenant('admin');
    makeTenant('www');
    $r = new TenantResolver();

    expect($r->resolve("acme.{$root}")?->subdomain)->toBe('acme')
        ->and($r->resolve("admin.{$root}"))->toBeNull()
        ->and($r->resolve("www.{$root}"))->toBeNull()
        ->and($r->resolve("a.b.{$root}"))->toBeNull()
        ->and($r->resolve("unknown.{$root}"))->toBeNull();
});

it('resolves a custom domain only when verified and active', function () {
    $tenant = makeTenant('bigco');
    $r = new TenantResolver();

    $d = TenantDomain::create([
        'tenant_id' => $tenant->id, 'domain' => 'bigco.co.za',
        'verification_token' => 'tok', 'cname_target' => 'edge.pendagraphics.co.za',
    ]);
    expect($r->resolve('bigco.co.za'))->toBeNull()
        ->and($r->isCertifiableHost('bigco.co.za'))->toBeFalse();

    $d->update(['verified_at' => now(), 'is_active' => true]);
    expect($r->resolve('bigco.co.za')?->id)->toBe($tenant->id)
        ->and($r->isCertifiableHost('bigco.co.za'))->toBeTrue();

    $tenant->update(['status' => Tenant::SUSPENDED]);
    expect($r->isCertifiableHost('bigco.co.za'))->toBeFalse();
});

it('normalises subdomains', function () {
    expect(TenantResolver::normaliseSubdomain('Acme Corp!!'))->toBe('acme-corp')
        ->and(TenantResolver::normaliseSubdomain('', 'My Company (Pty) Ltd'))->toBe('my-company-pty-ltd');
});
