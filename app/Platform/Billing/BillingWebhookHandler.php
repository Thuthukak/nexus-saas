<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use App\Kernel\Dtos\BillingNotificationTypes;
use App\Platform\Registry\TenantRegistry;
use App\Platform\Subscriptions\SubscriptionManagement;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies a VERIFIED, normalised processor event. The caller must have verified the signature first; nothing here
 * runs for an unverified request. Everything that changes subscription state happens in one transaction together with
 * the idempotency claim, so a failure rolls the claim back and the processor's retry is processed again.
 */
final class BillingWebhookHandler
{
    public const BILLING_PREFIX = 'Billing: ';

    public function __construct(
        private readonly SubscriptionManagement $subscriptions,
        private readonly InvoiceService $invoices,
        private readonly TenantRegistry $registry,
        private readonly PriceCatalogue $prices,
    ) {
    }

    /** @return array{tenant_id:string, subscription_status:string}|null null = no matching tenant */
    public function handle(BillingEvent $e): ?array
    {
        $after = [];

        $result = DB::transaction(function () use ($e, &$after): ?array {
            $sub = $this->match($e);
            if ($sub === null) {
                return null;
            }
            $claimed = DB::table('billing_events')->insertOrIgnore([
                'processor' => $e->processor, 'event_id' => $e->eventId, 'event_type' => $e->type,
                'tenant_id' => $sub->tenant_id, 'outcome' => 'applied', 'created_at' => now(),
            ]);
            if ($claimed === 0) {
                return ['tenant_id' => $sub->tenant_id, 'subscription_status' => $sub->status];   // a retry of something already done
            }

            $update = ['updated_at' => now()];
            if ($sub->processor === null || $sub->processor_reference === null) {
                $update['processor'] = $e->processor;                                              // adopt on first contact
                $update['processor_reference'] = $e->reference ?? $sub->processor_reference;
                $sub->processor = $e->processor;
            }

            $status = SubscriptionState::after($sub->status, $e->type);
            $outcome = 'applied';

            if ($e->type === BillingEvent::RENEWAL_SUCCEEDED && $sub->status !== SubscriptionState::CANCELLED) {
                $start = $e->periodStart ?? new \DateTimeImmutable();
                $end = $e->periodEnd ?? SubscriptionState::periodEnd($start, $sub->billing_cycle);
                $update += ['current_period_start' => $start, 'current_period_end' => $end, 'past_due_since' => null];
                $planKey = (string) DB::table('plans')->where('id', $sub->plan_id)->value('key');
                $amount = ($e->currency === null || $e->currency === $sub->currency) ? $e->amountMinor : null;
                $amount ??= $this->prices->quote($planKey, $sub->currency, $sub->billing_cycle, $sub->custom_price_minor)['charge_minor'] ?? 0;
                $this->invoices->issuePaid($sub, $planKey, $amount, $e->invoiceRef, $start, $end);
                $after[] = fn () => $this->reinstateIfBillingSuspended($sub->tenant_id);
            }

            if ($e->type === BillingEvent::PAYMENT_FAILED && $status === SubscriptionState::PAST_DUE && $sub->status !== SubscriptionState::PAST_DUE) {
                $update['past_due_since'] = now();
                $after[] = fn () => $this->subscriptions->notifyAdmins($sub->tenant_id, BillingNotificationTypes::PAYMENT_FAILED,
                    'We could not process your subscription payment',
                    'Your workspace keeps working for now. Please update your payment details within '.(int) config('billing.grace_days', 7).' days to avoid suspension.');
            }

            if ($e->type === BillingEvent::PLAN_CHANGED) {
                $outcome = $this->planChange($sub, $e, $after);
            }

            if ($e->type === BillingEvent::CANCELLED) {
                $after[] = fn () => $this->suspendForBilling($sub->tenant_id, 'Subscription ended');
            }

            $update['status'] = $status;
            DB::table('tenant_subscriptions')->where('id', $sub->id)->update($update);
            if ($outcome !== 'applied') {
                DB::table('billing_events')->where('processor', $e->processor)->where('event_id', $e->eventId)->update(['outcome' => $outcome]);
            }

            return ['tenant_id' => $sub->tenant_id, 'subscription_status' => $status];
        });

        foreach ($after as $step) {
            try {
                $step();
            } catch (Throwable $ex) {
                report($ex);   // the state change is committed; side effects are re-runnable by the enforcer/operator
            }
        }

        return $result;
    }

    private function planChange(object $sub, BillingEvent $e, array &$after): string
    {
        $planKey = $e->planKey ?? '';
        $cycle = $e->cycle ?? $sub->billing_cycle;
        $check = $this->subscriptions->check($sub->tenant_id, $planKey, $cycle);

        if (! $check->ok) {
            $seats = $check->usersToDeactivate > 0;
            $after[] = fn () => $this->subscriptions->notifyAdmins($sub->tenant_id, BillingNotificationTypes::PLAN_CHANGE_BLOCKED,
                'Your plan change could not be completed', (string) $check->reason);

            return $seats ? 'rejected_seats' : 'rejected_plan';
        }
        $after[] = fn () => $this->subscriptions->apply($sub->tenant_id, $planKey, $cycle);

        return 'applied';
    }

    private function match(BillingEvent $e): ?object
    {
        $q = fn () => DB::table('tenant_subscriptions')->orderByDesc('created_at')->lockForUpdate();

        if ($e->reference !== null) {
            $hit = $q()->where('processor', $e->processor)->where('processor_reference', $e->reference)->first();
            if ($hit !== null) {
                return $hit;
            }
        }
        if ($e->tenantId === null) {
            return null;
        }
        $hit = $q()->where('tenant_id', $e->tenantId)->first();
        if ($hit === null) {
            return null;
        }
        // Metadata alone must not let one processor (or a different subscription) steer a tenant already bound elsewhere.
        if ($hit->processor !== null && $hit->processor !== $e->processor) {
            return null;
        }
        if ($hit->processor_reference !== null && $e->reference !== null && $hit->processor_reference !== $e->reference) {
            return null;
        }

        return $hit;
    }

    private function suspendForBilling(string $tenantId, string $reason): void
    {
        $status = DB::table('tenants')->where('id', $tenantId)->value('status');
        if ($status === 'active') {
            $this->registry->changeStatus($tenantId, 'suspended', self::BILLING_PREFIX.$reason);
        }
    }

    /** Reinstate only a suspension the billing engine itself made, never one an operator made. */
    private function reinstateIfBillingSuspended(string $tenantId): void
    {
        $last = DB::table('tenant_status_events')->where('tenant_id', $tenantId)->orderByDesc('id')->first();
        if ($last !== null && $last->to_status === 'suspended' && $last->actor === 'system' && str_starts_with($last->reason, self::BILLING_PREFIX)) {
            $this->registry->changeStatus($tenantId, 'active', self::BILLING_PREFIX.'Payment received');
        }
    }

    public function enforcePastDue(): int
    {
        $n = 0;
        $due = DB::table('tenant_subscriptions as s')->join('tenants as t', 't.id', '=', 's.tenant_id')
            ->where('s.status', 'past_due')->where('t.status', 'active')->whereNotNull('s.past_due_since')
            ->where('s.past_due_since', '<=', now()->subDays(max(0, (int) config('billing.grace_days', 7))))
            ->pluck('s.tenant_id');
        foreach ($due as $tenantId) {
            $this->suspendForBilling($tenantId, 'Payment overdue');
            $n++;
        }
        $ended = DB::table('tenant_subscriptions as s')->join('tenants as t', 't.id', '=', 's.tenant_id')
            ->where('s.status', 'cancelled')->where('t.status', 'active')->pluck('s.tenant_id');
        foreach ($ended as $tenantId) {
            $this->suspendForBilling($tenantId, 'Subscription ended');
            $n++;
        }

        return $n;
    }
}
