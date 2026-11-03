<?php

declare(strict_types=1);

namespace App\Platform\Billing\Processors;

use App\Platform\Billing\BillingEvent;
use App\Platform\Billing\SubscriptionSyncRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Paystack. Webhook: `X-Paystack-Signature` = HMAC-SHA512(secret key, raw body), hex. Paystack sends no event id, so the
 * body hash is the idempotency key. The processor reference we store is the Paystack CUSTOMER code (present on every event).
 * NOT run against the real Paystack API from the build sandbox.
 *
 * @param array<string,string> $planCodes "plan.CURRENCY.cycle" => Paystack plan code
 */
final class PaystackProcessor implements BillingProcessor
{
    public function __construct(private readonly string $secret, private readonly array $planCodes = [])
    {
    }

    public function key(): string
    {
        return 'paystack';
    }

    public function verify(string $payload, array $headers): bool
    {
        $sig = Json::header($headers, 'X-Paystack-Signature');

        return $this->secret !== '' && $sig !== null && preg_match('/^[0-9a-f]{128}$/D', $sig) === 1
            && hash_equals(hash_hmac('sha512', $payload, $this->secret), $sig);
    }

    public function parse(string $payload): ?BillingEvent
    {
        $e = Json::object($payload);
        $d = $e['data'] ?? null;
        if (! is_array($d)) {
            throw new \InvalidArgumentException('Missing data.');
        }
        $id = 'sha256:'.hash('sha256', $payload);
        $ref = Json::str($d['customer']['customer_code'] ?? null);
        $tenant = Json::uuid($d['metadata']['tenant_id'] ?? null);
        $paidAt = isset($d['paid_at']) && is_string($d['paid_at']) ? date_create_immutable($d['paid_at']) ?: null : null;

        switch ($e['event'] ?? null) {
            case 'charge.success':
                return new BillingEvent('paystack', $id, BillingEvent::RENEWAL_SUCCEEDED, tenantId: $tenant, reference: $ref,
                    amountMinor: Json::minor($d['amount'] ?? null), currency: Json::currency($d['currency'] ?? null), invoiceRef: Json::str((string) ($d['id'] ?? ''), 60), periodStart: $paidAt);
            case 'invoice.payment_failed':
                return new BillingEvent('paystack', $id, BillingEvent::PAYMENT_FAILED, tenantId: $tenant, reference: $ref);
            case 'subscription.disable':
                return new BillingEvent('paystack', $id, BillingEvent::CANCELLED, tenantId: $tenant, reference: $ref);
            case 'subscription.create':
                $code = Json::str($d['plan']['plan_code'] ?? null, 60);
                $hit = $code === null ? null : array_search($code, $this->planCodes, true);
                if (! is_string($hit) || substr_count($hit, '.') !== 2) {
                    return null;
                }
                [$plan, , $cycle] = explode('.', $hit);

                return new BillingEvent('paystack', $id, BillingEvent::PLAN_CHANGED, tenantId: $tenant, reference: $ref, planKey: $plan, cycle: $cycle);
            default:
                return null;
        }
    }

    public function syncSubscription(SubscriptionSyncRequest $r): string
    {
        $code = $this->planCodes["{$r->planKey}.{$r->currency}.{$r->cycle}"] ?? throw new RuntimeException("Paystack: no plan code configured for {$r->planKey}.{$r->currency}.{$r->cycle}.");
        $http = Http::withToken((string) config('billing.processors.paystack.api_key'))->acceptJson()->timeout(15);

        $cust = $http->post('https://api.paystack.co/customer', ['email' => $r->customerEmail, 'metadata' => ['tenant_id' => $r->tenantId]]);
        $customer = $cust->json('data.customer_code');
        if (! $cust->successful() || ! is_string($customer)) {
            throw new RuntimeException('Paystack: could not create the customer.');
        }
        $sub = $http->post('https://api.paystack.co/subscription', ['customer' => $customer, 'plan' => $code]);
        if (! $sub->successful()) {
            throw new RuntimeException('Paystack: subscription request failed (the customer may need to authorise a card first).');
        }

        return $customer;
    }
}
