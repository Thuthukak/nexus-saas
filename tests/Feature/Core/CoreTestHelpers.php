<?php

use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| Shared helpers for the Core feature tests (each test file require_once's this).
| Function names are prefixed `core` to avoid clashes with other test files.
*/

/** Make a tenant on a seeded plan (starter | professional | enterprise | a key from coreEnsurePlan). */
function coreMakeTenant(string $plan = 'starter', string $currency = 'ZAR'): Tenant
{
    if (DB::table('plans')->count() === 0) {
        (new PlanCatalogueSeeder())->run();
    }

    $tenant = Tenant::create([
        'name' => 'T', 'subdomain' => 't-'.Str::lower(Str::random(8)),
        'country_code' => $currency === 'ZAR' ? 'ZA' : 'US', 'billing_currency' => $currency,
    ]);

    DB::table('tenant_subscriptions')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id,
        'plan_id' => DB::table('plans')->where('key', $plan)->value('id'),
        'billing_cycle' => 'monthly', 'currency' => $currency, 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $tenant;
}

/** Insert a throwaway plan with a given seat limit (rolled back with the test). Returns its key. */
function coreEnsurePlan(string $key, ?int $seats): string
{
    $planId = DB::table('plans')->insertGetId([
        'key' => $key, 'name' => ucfirst($key), 'sort_order' => 99, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach (['ZAR', 'USD'] as $currency) {
        DB::table('plan_limits')->insert([
            'plan_id' => $planId, 'currency' => $currency, 'seats' => $seats,
            'tickets_per_month' => null, 'volume_per_month_minor' => null, 'custom_domain_allowed' => false,
        ]);
    }

    return $key;
}

function coreActAs(Tenant $tenant): void
{
    app(TenantContext::class)->set($tenant->id);
}

/**
 * Run a statement that is EXPECTED to fail inside a savepoint. PostgreSQL aborts the whole
 * transaction on an error, which would break every later query in the same test; a savepoint
 * rolls back just this statement.
 */
function coreInSavepoint(callable $callback): mixed
{
    return DB::transaction($callback);
}
