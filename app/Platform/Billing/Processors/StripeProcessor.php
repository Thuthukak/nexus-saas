<?php

declare(strict_types=1);

namespace App\Platform\Billing\Processors;

use App\Platform\Billing\BillingEvent;
use App\Platform\Billing\SubscriptionSyncRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Stripe. Webhook: `Stripe-Signature: t=<unix>,v1=<hex>` where v1 = HMAC-SHA256(secret, "t.payload"), 5 minute tolerance.
 * Outbound calls use inline price_data so no Stripe price catalogue has to be kept in sync.
 * NOT run against the real Stripe API from the build sandbox: check request shapes in Stripe test mode.
 */
final class StripeProcessor implements BillingProcessor
{
    public function __construct(private readonly string $secret, private readonly string $apiKey = '', private readonly int $tolerance = 300, private readonly ?\Closure $clock = null)
    {
    }

    public function key(): string
    {
        return 'stripe';
    }

    public function verify(string $payload, array $headers): bool
    {
        $header = Json::header($headers, 'Stripe-Signature');
        if ($this->secret === '' || $header === null || strlen($header) > 600) {
            return false;
        }
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't' && ctype_digit($v)) {
                $t = (int) $v;
            } elseif ($k === 'v1' && preg_match('/^[0-9a-f]{64}$/D', $v) === 1) {
                $sigs[] = $v;
            }
        }
        $now = $this->clock ? ($this->clock)() : time();
        if ($t === null || $sigs === [] || abs($now - $t) > $this->tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $t.'.'.$payload, $this->secret);
        foreach ($sigs as $s) {
            if (hash_equals($expected, $s)) {
                return true;
            }
        }

        return false;
    }

    public function parse(string $payload): ?BillingEvent
    {
        $e = Json::object($payload);
        $id = Json::str($e['id'] ?? null) ?? throw new \InvalidArgumentException('Missing event id.');
        $obj = $e['data']['object'] ?? null;
        if (! is_array($obj)) {
            throw new \InvalidArgumentException('Missing event object.');
        }

        switch ($e['type'] ?? null) {
            case 'invoice.paid':
            case 'invoice.payment_failed':
                $line = is_array($obj['lines']['data'][0] ?? null) ? $obj['lines']['data'][0] : [];
                $meta = $obj['subscription_details']['metadata'] ?? $obj['metadata'] ?? [];

                return new BillingEvent(
                    'stripe', $id, $e['type'] === 'invoice.paid' ? BillingEvent::RENEWAL_SUCCEEDED : BillingEvent::PAYMENT_FAILED,
                    tenantId: Json::uuid($meta['tenant_id'] ?? null), reference: Json::str($obj['subscription'] ?? null),
                    amountMinor: Json::minor($obj['amount_paid'] ?? null), currency: Json::currency($obj['currency'] ?? null),
                    invoiceRef: Json::str($obj['id'] ?? null),
                    periodStart: Json::ts($line['period']['start'] ?? null), periodEnd: Json::ts($line['period']['end'] ?? null),
                );
            case 'customer.subscription.updated':
                $meta = is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [];
                if (Json::str($meta['plan'] ?? null, 40) === null) {
                    return null;   // not one of ours / nothing about the plan changed
                }

                return new BillingEvent('stripe', $id, BillingEvent::PLAN_CHANGED, tenantId: Json::uuid($meta['tenant_id'] ?? null),
                    reference: Json::str($obj['id'] ?? null), planKey: Json::str($meta['plan'], 40), cycle: Json::str($meta['billing_cycle'] ?? null, 10));
            case 'customer.subscription.deleted':
                $meta = is_array($obj['metadata'] ?? null) ? $obj['metadata'] : [];

                return new BillingEvent('stripe', $id, BillingEvent::CANCELLED, tenantId: Json::uuid($meta['tenant_id'] ?? null), reference: Json::str($obj['id'] ?? null));
            default:
                return null;
        }
    }

    public function syncSubscription(SubscriptionSyncRequest $r): string
    {
        $meta = ['tenant_id' => $r->tenantId, 'plan' => $r->planKey, 'billing_cycle' => $r->cycle];
        $price = [
            'price_data' => [
                'currency' => strtolower($r->currency), 'unit_amount' => $r->chargeMinor,
                'recurring' => ['interval' => $r->cycle === 'annual' ? 'year' : 'month'],
                'product_data' => ['name' => "Butter {$r->planName} ({$r->cycle})"],
            ],
        ];
        $http = Http::withToken($this->apiKey)->asForm()->acceptJson()->timeout(15);

        if ($r->existingReference !== null) {
            $current = $http->get("https://api.stripe.com/v1/subscriptions/{$r->existingReference}");
            $item = $current->json('items.data.0.id');
            if (! $current->successful() || ! is_string($item)) {
                throw new RuntimeException('Stripe: could not read the current subscription.');
            }
            $res = $http->post("https://api.stripe.com/v1/subscriptions/{$r->existingReference}", [
                'items' => [['id' => $item, ...$price]], 'metadata' => $meta, 'proration_behavior' => 'create_prorations',
            ]);
        } else {
            $customer = $http->post('https://api.stripe.com/v1/customers', ['email' => $r->customerEmail, 'name' => $r->customerName, 'metadata' => ['tenant_id' => $r->tenantId]]);
            if (! $customer->successful() || ! is_string($customer->json('id'))) {
                throw new RuntimeException('Stripe: could not create the customer.');
            }
            $res = $http->post('https://api.stripe.com/v1/subscriptions', [
                'customer' => $customer->json('id'), 'items' => [$price], 'metadata' => $meta, 'collection_method' => 'send_invoice', 'days_until_due' => 7,
            ]);
        }
        $ref = $res->json('id');
        if (! $res->successful() || ! is_string($ref)) {
            throw new RuntimeException('Stripe: subscription request failed.');
        }

        return $ref;
    }
}
