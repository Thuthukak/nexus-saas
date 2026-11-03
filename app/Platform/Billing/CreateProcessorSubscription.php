<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Platform\Onboarding\TenantProvisioned;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * After a tenant is provisioned, create its subscription at the configured processor (the external call the provisioner
 * deliberately keeps outside its database transaction). No processor for the currency = manual billing: nothing to do.
 * A failure never blocks signup: the tenant keeps its plan and the error is reported for the operator.
 */
final class CreateProcessorSubscription
{
    public function __construct(private readonly ProcessorRegistry $processors, private readonly PriceCatalogue $prices)
    {
    }

    public function handle(TenantProvisioned $event): void
    {
        $d = $event->dto;
        $key = $this->processors->keyForCurrency($d->billingCurrency);
        $processor = $key === null ? null : $this->processors->get($key);
        if ($processor === null) {
            return;
        }
        try {
            $sub = DB::table('tenant_subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')->where('s.tenant_id', $d->tenantId)
                ->orderByDesc('s.created_at')->first(['s.id', 's.billing_cycle', 's.currency', 's.custom_price_minor', 's.processor_reference', 'p.key', 'p.name']);
            if ($sub === null || $sub->processor_reference !== null) {
                return;
            }
            $quote = $this->prices->quote($sub->key, $sub->currency, $sub->billing_cycle, $sub->custom_price_minor);
            if ($quote === null) {
                return;
            }
            $ref = $processor->syncSubscription(new SubscriptionSyncRequest($d->tenantId, $sub->key, $sub->name, $sub->billing_cycle, $sub->currency, $quote['charge_minor'], $d->adminEmail, $d->adminName));
            DB::table('tenant_subscriptions')->where('id', $sub->id)->update(['processor' => $key, 'processor_reference' => $ref, 'updated_at' => now()]);
        } catch (Throwable $e) {
            report($e);
        }
    }
}
