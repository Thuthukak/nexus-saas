<?php

use App\Tenancy\TenantContext;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;

/* Shared by the overlay-6 feature tests. Needs the phpunit.xml env from SETUP-4.md. */

/** Sign up a tenant through the real endpoint; returns [tenantId, adminUser] with tenant context set. */
function coreHttpTenant(string $sub): array
{
    if (DB::table('plans')->count() === 0) {
        (new PlanCatalogueSeeder())->run();
    }
    $id = test()->postJson('http://platform.test/api/tenants', [
        'company_name' => ucfirst($sub), 'admin_name' => 'Ada', 'admin_email' => "{$sub}@example.com",
        'admin_password' => 'Str0ng-password!', 'requested_subdomain' => $sub, 'business_type' => 'retail',
        'subscription_plan' => 'professional', 'billing_cycle' => 'monthly', 'country_code' => 'ZA',
    ])->assertCreated()->json('tenant_id');

    app(TenantContext::class)->set($id);

    return [$id, User::where('email', "{$sub}@example.com")->firstOrFail()];
}

/** A signed-in-capable user with NO role (so no permissions) in the current tenant. */
function coreHttpPlainUser(string $sub): User
{
    return User::create(['name' => 'Pat', 'email' => "pat@{$sub}.example.com", 'password' => 'Str0ng-password!']);
}

function coreHost(string $sub, string $path = '/'): string
{
    return "http://{$sub}.platform.test".$path;
}

function coreAsTenant(string $id): void {
    app(\App\Tenancy\TenantContext::class)->set($id); 
}

/** Forget any actingAs() user so the next request in the same test is a genuine guest. */
function coreAsGuest(): void
{
    app('auth')->forgetGuards();
}