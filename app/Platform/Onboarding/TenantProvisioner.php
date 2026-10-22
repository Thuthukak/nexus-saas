<?php

declare(strict_types=1);

namespace App\Platform\Onboarding;

use App\Kernel\Contracts\ProvisionsTenantAdmin;
use App\Kernel\Dtos\ProvisionTenantAdminDTO;
use App\Platform\Billing\PlanPricing;
use App\Platform\Modules\ModuleKey;
use App\Platform\Modules\ModuleManager;
use App\Tenancy\Handoff\HandoffIssuer;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantResolver;
use App\Tenancy\TenantUrl;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * TenantOnboarding. Tenant, subscription, module enablement, admin user, seat, platform email
 * and handoff token are created in ONE database transaction: any failure rolls back all of it,
 * so a tenant can never exist with an active plan and no admin.
 *
 * Order matters: the plan is activated BEFORE the admin is created because the seat rule needs
 * an active subscription.
 *
 * Do not call this from inside another open transaction: the TenantProvisioned event (welcome
 * email) is dispatched as soon as the outermost call here returns.
 */
final class TenantProvisioner
{
    public function __construct(
        private readonly ProvisionsTenantAdmin $admins,
        private readonly ModuleManager $modules,
        private readonly HandoffIssuer $handoff,
        private readonly TenantContext $context,
    ) {
    }

    /** @throws SignupConflict */
    public function provision(SignupDTO $in): ProvisionedTenantDTO
    {
        try {
            $result = DB::transaction(fn () => $this->create($in));
        } catch (UniqueConstraintViolationException) {
            // Lost a race on subdomain or email: the unique indexes are the real guard.
            throw new SignupConflict();
        } finally {
            $this->context->clear();
        }

        // Committed. Side effects must never fail a signup that already succeeded.
        try {
            event(new TenantProvisioned($result));
        } catch (Throwable $e) {
            report($e);
        }

        return $result;
    }

    private function create(SignupDTO $in): ProvisionedTenantDTO
    {
        if (TenantResolver::isReserved($in->subdomain)
            || Tenant::query()->where('subdomain', $in->subdomain)->exists()
            || DB::table('platform_emails')->where('email_normalised', $in->adminEmail)->exists()) {
            throw new SignupConflict();
        }

        $currency = PlanPricing::currencyForCountry($in->countryCode);

        $tenant = Tenant::create([
            'name' => $in->companyName,
            'subdomain' => $in->subdomain,
            'status' => Tenant::ACTIVE,            // the tenants table is the TenantRegistry
            'country_code' => $in->countryCode,
            'billing_currency' => $currency,
            'business_type' => $in->businessType,  // CoreTerminology resolves labels from this
        ]);

        $planId = (int) DB::table('plans')->where('key', $in->subscriptionPlan)->value('id');
        $this->activatePlan($tenant->id, $planId, $in->billingCycle, $currency);
        $this->enableModules($tenant->id, $planId);

        // Core creates the roles, the admin user (taking seat 1) and the platform email row.
        $admin = $this->admins->provision(new ProvisionTenantAdminDTO(
            $tenant->id, $in->adminName, $in->adminEmail, $in->adminPassword,
        ));

        // Leave no tenant context behind on the connection (matters when nested in another transaction).
        DB::select("select set_config(?, '', true)", [config('tenancy.rls_variable')]);

        return new ProvisionedTenantDTO(
            tenantId: $tenant->id,
            subdomain: $in->subdomain,
            workspaceUrl: TenantUrl::workspace($in->subdomain),
            handoffUrl: $this->handoff->issue($tenant->id, $admin->userId, $in->subdomain),
            billingCurrency: $currency,
            subscriptionPlan: $in->subscriptionPlan,
            companyName: $in->companyName,
            adminName: $in->adminName,
            adminEmail: $admin->email,
        );
    }

    /**
     * Records the subscription locally as active. Signup collects no payment details, so the
     * processor-side subscription is created afterwards by the Billing Engine (it listens for
     * TenantProvisioned); that external call cannot be part of this database transaction.
     */
    private function activatePlan(string $tenantId, int $planId, string $cycle, string $currency): void
    {
        $start = now();

        DB::table('tenant_subscriptions')->insert([
            'id' => (string) Str::uuid(),
            'tenant_id' => $tenantId,
            'plan_id' => $planId,
            'billing_cycle' => $cycle,
            'currency' => $currency,
            'status' => 'active',
            'current_period_start' => $start,
            'current_period_end' => $cycle === 'annual' ? $start->copy()->addYear() : $start->copy()->addMonth(),
            'created_at' => $start,
            'updated_at' => $start,
        ]);
    }

    private function enableModules(string $tenantId, int $planId): void
    {
        // Core is mandatory and on by default for every tenant.
        DB::table('tenant_modules')->insert([
            'tenant_id' => $tenantId,
            'module_key' => ModuleKey::Core->value,
            'is_enabled' => true,
            'first_enabled_at' => now(),
            'enabled_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Every other module the plan includes, through ModuleManager so entitlement and
        // prerequisite rules apply exactly as they will for a tenant admin later.
        $included = DB::table('plan_modules')->where('plan_id', $planId)->orderBy('module_key')->pluck('module_key');
        foreach ($included as $module) {
            if ($module !== ModuleKey::Core->value) {
                $this->modules->enable($tenantId, $module);
            }
        }
    }
}
