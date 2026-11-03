<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Platform\Billing\BillingWebhookHandler;
use App\Platform\Subscriptions\SubscriptionManagement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Hourly: suspend tenants whose grace period ran out or whose subscription ended; heal modules/domains left out of step with the plan. */
final class EnforcePastDueCommand extends Command
{
    protected $signature = 'billing:enforce-past-due';
    protected $description = 'Suspend overdue or ended subscriptions and reconcile plan entitlements';

    public function handle(BillingWebhookHandler $billing, SubscriptionManagement $plans): int
    {
        $suspended = $billing->enforcePastDue();

        $drifted = DB::table('tenant_modules as tm')->join('tenant_subscriptions as s', fn ($j) => $j->on('s.tenant_id', '=', 'tm.tenant_id')->whereIn('s.status', ['active', 'past_due']))
            ->where('tm.is_enabled', true)->where('tm.module_key', '<>', 'Core')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('plan_modules as pm')->whereColumn('pm.plan_id', 's.plan_id')->whereColumn('pm.module_key', 'tm.module_key'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('tenant_addons as a')->whereColumn('a.tenant_id', 'tm.tenant_id')->whereColumn('a.module_key', 'tm.module_key')->where('a.is_active', true))
            ->distinct()->pluck('tm.tenant_id');
        foreach ($drifted as $tenantId) {
            $plans->reconcile($tenantId);
        }

        $this->info("Suspended {$suspended}, reconciled {$drifted->count()}.");

        return self::SUCCESS;
    }
}
