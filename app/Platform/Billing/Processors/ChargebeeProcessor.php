<?php

declare(strict_types=1);

namespace App\Platform\Billing\Processors;

use App\Platform\Billing\BillingEvent;
use App\Platform\Billing\SubscriptionSyncRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Chargebee. Webhook: Chargebee has no payload HMAC; it sends HTTP Basic credentials you set on the webhook. Verified in constant time.
 * Item prices must exist in Chargebee with the id convention "{plan}-{currency}-{cycle}" (e.g. starter-usd-monthly).
 * NOT run against the real Chargebee API from the build sandbox.
 */
final class ChargebeeProcessor implements BillingProcessor
{
    public function __construct(private readonly string $username, private readonly string $password, private readonly string $site = '', private readonly string $apiKey = '')
    {
    }

    public function key(): string
    {
        return 'chargebee';
    }

    public function verify(string $payload, array $headers): bool
    {
        $h = Json::header($headers, 'Authorization');
        if ($this->username === '' || $this->password === '' || $h === null || stripos($h, 'Basic ') !== 0) {
            return false;
        }
        $given = base64_decode(substr($h, 6), true);

        return $given !== false && hash_equals($this->username.':'.$this->password, $given);
    }

    public function parse(string $payload): ?BillingEvent
    {
        $e = Json::object($payload);
        $id = Json::str($e['id'] ?? null) ?? throw new \InvalidArgumentException('Missing event id.');
        $c = is_array($e['content'] ?? null) ? $e['content'] : [];
        $sub = is_array($c['subscription'] ?? null) ? $c['subscription'] : [];
        $meta = is_array($sub['meta_data'] ?? null) ? $sub['meta_data'] : [];
        $tenant = Json::uuid($meta['tenant_id'] ?? null);
        $ref = Json::str($sub['id'] ?? null);

        switch ($e['event_type'] ?? null) {
            case 'payment_succeeded':
                $tx = is_array($c['transaction'] ?? null) ? $c['transaction'] : [];
                $inv = is_array($c['invoice'] ?? null) ? $c['invoice'] : [];

                return new BillingEvent('chargebee', $id, BillingEvent::RENEWAL_SUCCEEDED, tenantId: $tenant, reference: $ref,
                    amountMinor: Json::minor($tx['amount'] ?? null), currency: Json::currency($tx['currency_code'] ?? null), invoiceRef: Json::str($inv['id'] ?? null),
                    periodStart: Json::ts($sub['current_term_start'] ?? null), periodEnd: Json::ts($sub['current_term_end'] ?? null));
            case 'payment_failed':
                return new BillingEvent('chargebee', $id, BillingEvent::PAYMENT_FAILED, tenantId: $tenant, reference: $ref);
            case 'subscription_changed':
                if (Json::str($meta['plan'] ?? null, 40) === null) {
                    return null;
                }

                return new BillingEvent('chargebee', $id, BillingEvent::PLAN_CHANGED, tenantId: $tenant, reference: $ref, planKey: Json::str($meta['plan'], 40), cycle: Json::str($meta['billing_cycle'] ?? null, 10));
            case 'subscription_cancelled':
                return new BillingEvent('chargebee', $id, BillingEvent::CANCELLED, tenantId: $tenant, reference: $ref);
            default:
                return null;
        }
    }

    public function syncSubscription(SubscriptionSyncRequest $r): string
    {
        $base = "https://{$this->site}.chargebee.com/api/v2";
        $http = Http::withBasicAuth($this->apiKey, '')->asForm()->acceptJson()->timeout(15);
        $itemPrice = strtolower("{$r->planKey}-{$r->currency}-{$r->cycle}");
        $meta = json_encode(['tenant_id' => $r->tenantId, 'plan' => $r->planKey, 'billing_cycle' => $r->cycle], JSON_THROW_ON_ERROR);

        if ($r->existingReference !== null) {
            $res = $http->post("{$base}/subscriptions/{$r->existingReference}/update_for_items", [
                'subscription_items' => [['item_price_id' => $itemPrice, 'quantity' => 1]], 'meta_data' => $meta,
            ]);
        } else {
            $res = $http->post("{$base}/subscriptions/create_with_items", [
                'subscription_items' => [['item_price_id' => $itemPrice, 'quantity' => 1]], 'meta_data' => $meta,
                'customer' => ['email' => $r->customerEmail, 'first_name' => $r->customerName],
            ]);
        }
        $ref = $res->json('subscription.id');
        if (! $res->successful() || ! is_string($ref)) {
            throw new RuntimeException('Chargebee: subscription request failed.');
        }

        return $ref;
    }
}
