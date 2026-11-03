<?php

declare(strict_types=1);

namespace App\Platform\Subscriptions;

use App\Kernel\Contracts\TenantAdminDirectory;
use App\Kernel\Contracts\WorkspaceUserCounter;
use App\Kernel\Dtos\BillingNotificationTypes;
use App\Kernel\Dtos\NotificationRequested;
use App\Kernel\Dtos\NotificationRequestedDTO;
use App\Platform\Modules\DependencyGraph;
use App\Platform\Modules\Entitlements;
use App\Platform\Modules\ModuleException;
use App\Platform\Modules\ModuleKey;
use App\Platform\Modules\ModuleManager;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SubscriptionManagement: validates a plan change, applies it, and recomputes what the tenant is entitled to.
 * Seats and usage allowances are read live from the plan (UsageMeter), so they follow the plan row; modules and the
 * custom domain are persisted state and are reconciled here. Nothing is ever deleted.
 */
final class SubscriptionManagement
{
    public function __construct(
        private readonly WorkspaceUserCounter $users,
        private readonly TenantAdminDirectory $admins,
        private readonly ModuleManager $modules,
        private readonly Entitlements $entitlements,
    ) {
    }

    public function check(string $tenantId, string $planKey, string $cycle): PlanChangeCheck
    {
        if (! in_array($cycle, ['monthly', 'annual'], true)) {
            return new PlanChangeCheck(false, 'Unknown billing cycle.');
        }
        $tenant = DB::table('tenants')->where('id', $tenantId)->first(['billing_currency']);
        $plan = DB::table('plans')->where('key', $planKey)->where('is_active', true)->first(['id', 'name']);
        if ($tenant === null || $plan === null) {
            return new PlanChangeCheck(false, 'Unknown plan.');
        }
        if (! DB::table('plan_prices')->where('plan_id', $plan->id)->where('currency', $tenant->billing_currency)->where('cycle', $cycle)->exists()) {
            return new PlanChangeCheck(false, 'That plan is not priced for this billing cycle.');
        }

        $seats = DB::table('plan_limits')->where('plan_id', $plan->id)->where('currency', $tenant->billing_currency)->value('seats');
        $active = $this->users->activeUsers($tenantId);
        if ($seats !== null && $active > (int) $seats) {
            $n = $active - (int) $seats;

            return new PlanChangeCheck(false, "Your workspace has {$active} active users but the {$plan->name} plan allows {$seats}. Deactivate {$n} user".($n === 1 ? '' : 's').' before changing to this plan.', [], $n);
        }

        return new PlanChangeCheck(true, null, $this->modulesLostOn($tenantId, (int) $plan->id));
    }

    /** Switch the subscription to the plan and reconcile. Call only after check() passed (or the processor confirmed). */
    public function apply(string $tenantId, string $planKey, string $cycle, bool $notify = true): void
    {
        $plan = DB::table('plans')->where('key', $planKey)->first(['id', 'name']);
        if ($plan === null) {
            return;
        }
        $changed = DB::transaction(function () use ($tenantId, $plan, $cycle): bool {
            $sub = DB::table('tenant_subscriptions')->where('tenant_id', $tenantId)->orderByDesc('created_at')->lockForUpdate()->first();
            if ($sub === null || ((int) $sub->plan_id === (int) $plan->id && $sub->billing_cycle === $cycle)) {
                return false;
            }
            DB::table('tenant_subscriptions')->where('id', $sub->id)->update(['plan_id' => $plan->id, 'billing_cycle' => $cycle, 'updated_at' => now()]);

            return true;
        });
        if (! $changed) {
            return;
        }

        $this->reconcile($tenantId);
        if ($notify) {
            $this->notifyAdmins($tenantId, BillingNotificationTypes::PLAN_CHANGED, "Your plan is now {$plan->name} ({$cycle})",
                'Modules, user seats and usage allowances now follow the new plan. Data in any module the plan no longer includes is kept.');
        }
    }

    /** Idempotent: switch off modules the plan no longer includes and deactivate a custom domain it no longer allows. */
    public function reconcile(string $tenantId): void
    {
        $entitled = $this->entitlements->entitledModules($tenantId);
        $enabled = $this->modules->activeModules($tenantId);
        $pending = array_values(array_diff($enabled, $entitled, [ModuleKey::Core->value]));

        // Disable dependents before the modules they depend on; ModuleManager refuses the other order.
        for ($guard = count($pending) + 1; $pending !== [] && $guard > 0; $guard--) {
            foreach ($pending as $i => $module) {
                try {
                    $this->modules->disable($tenantId, $module);   // data kept: only the flag flips
                    unset($pending[$i]);
                } catch (ModuleException) {
                    // still has enabled dependents; retry next pass
                }
            }
            $pending = array_values($pending);
        }

        $allowed = (bool) DB::table('tenants as t')
            ->join('tenant_subscriptions as s', 's.tenant_id', '=', 't.id')
            ->join('plan_limits as pl', fn ($j) => $j->on('pl.plan_id', '=', 's.plan_id')->on('pl.currency', '=', 't.billing_currency'))
            ->where('t.id', $tenantId)->whereIn('s.status', ['active', 'past_due'])->value('pl.custom_domain_allowed');
        if (! $allowed) {
            // Routing stops; the record stays; the platform subdomain keeps working (it is not in this table).
            DB::table('tenant_domains')->where('tenant_id', $tenantId)->where('is_active', true)->update(['is_active' => false, 'updated_at' => now()]);
        }
    }

    public function notifyAdmins(string $tenantId, string $type, string $title, string $body): void
    {
        try {
            foreach ($this->admins->activeAdmins($tenantId) as $admin) {
                event(new NotificationRequested(new NotificationRequestedDTO($tenantId, $admin->userId, $type, $title, $body)));
            }
        } catch (Throwable $e) {
            report($e);   // a failed notice never undoes billing state
        }
    }

    /** @return list<string> */
    private function modulesLostOn(string $tenantId, int $planId): array
    {
        $included = DB::table('plan_modules')->where('plan_id', $planId)->pluck('module_key')->all();
        $addons = DB::table('tenant_addons')->where('tenant_id', $tenantId)->where('is_active', true)->pluck('module_key')->all();

        return array_values(array_diff($this->modules->activeModules($tenantId), $included, $addons, [ModuleKey::Core->value]));
    }
}
