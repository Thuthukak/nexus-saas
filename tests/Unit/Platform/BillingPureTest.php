<?php

use App\Platform\Billing\BillingEvent;
use App\Platform\Billing\Processors\ChargebeeProcessor;
use App\Platform\Billing\Processors\PaystackProcessor;
use App\Platform\Billing\Processors\StripeProcessor;
use App\Platform\Billing\SubscriptionState;

const BP_TENANT = '0b9f6c34-5a1e-4d52-9a57-3f6a1c2d8e90';

function bpStripeSig(string $body, string $secret, int $t): array
{
    return ['Stripe-Signature' => "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret)];
}

it('verifies a Stripe signature and refuses tampering, stale timestamps and junk', function () {
    $now = 1_800_000_000;
    $p = new StripeProcessor('whsec_x', '', 300, fn () => $now);
    $body = '{"id":"evt_1"}';

    expect($p->verify($body, bpStripeSig($body, 'whsec_x', $now)))->toBeTrue();
    expect($p->verify($body.' ', bpStripeSig($body, 'whsec_x', $now)))->toBeFalse();
    expect($p->verify($body, bpStripeSig($body, 'other', $now)))->toBeFalse();
    expect($p->verify($body, bpStripeSig($body, 'whsec_x', $now - 301)))->toBeFalse();
    expect($p->verify($body, bpStripeSig($body, 'whsec_x', $now + 301)))->toBeFalse();
    expect($p->verify($body, []))->toBeFalse();
    expect($p->verify($body, ['Stripe-Signature' => 't=abc,v1=zz']))->toBeFalse();
    expect((new StripeProcessor('', '', 300, fn () => $now))->verify($body, bpStripeSig($body, '', $now)))->toBeFalse();   // no secret configured
    expect($p->verify($body, ['stripe-signature' => [bpStripeSig($body, 'whsec_x', $now)['Stripe-Signature']]]))->toBeTrue();   // Laravel header shape
});

it('parses Stripe invoice, plan and cancel events', function () {
    $p = new StripeProcessor('s');
    $paid = $p->parse(json_encode(['id' => 'evt_9', 'type' => 'invoice.paid', 'data' => ['object' => [
        'id' => 'in_1', 'subscription' => 'sub_1', 'amount_paid' => 4900, 'currency' => 'usd',
        'subscription_details' => ['metadata' => ['tenant_id' => BP_TENANT]],
        'lines' => ['data' => [['period' => ['start' => 1_800_000_000, 'end' => 1_802_592_000]]]],
    ]]]));
    expect([$paid->type, $paid->reference, $paid->amountMinor, $paid->currency, $paid->tenantId, $paid->invoiceRef])
        ->toBe([BillingEvent::RENEWAL_SUCCEEDED, 'sub_1', 4900, 'USD', BP_TENANT, 'in_1']);
    expect($paid->periodEnd->getTimestamp())->toBe(1_802_592_000);

    $failed = $p->parse(json_encode(['id' => 'evt_8', 'type' => 'invoice.payment_failed', 'data' => ['object' => ['id' => 'in_2', 'subscription' => 'sub_1']]]));
    expect($failed->type)->toBe(BillingEvent::PAYMENT_FAILED);

    $plan = $p->parse(json_encode(['id' => 'evt_7', 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_1', 'metadata' => ['tenant_id' => BP_TENANT, 'plan' => 'starter', 'billing_cycle' => 'annual']]]]));
    expect([$plan->type, $plan->planKey, $plan->cycle])->toBe([BillingEvent::PLAN_CHANGED, 'starter', 'annual']);

    expect($p->parse(json_encode(['id' => 'evt_6', 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_1']]])))->toBeNull();
    expect($p->parse(json_encode(['id' => 'evt_5', 'type' => 'customer.created', 'data' => ['object' => []]])))->toBeNull();
    expect($p->parse(json_encode(['id' => 'evt_4', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_1']]]))->type)->toBe(BillingEvent::CANCELLED);
});

it('treats a non-uuid tenant id and malformed payloads safely', function () {
    $p = new StripeProcessor('s');
    $e = $p->parse(json_encode(['id' => 'e', 'type' => 'invoice.paid', 'data' => ['object' => ['subscription_details' => ['metadata' => ['tenant_id' => "1' or '1'='1"]]]]]));
    expect($e->tenantId)->toBeNull();
    expect(fn () => $p->parse('not json'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $p->parse('[1,2]'))->toThrow(InvalidArgumentException::class);
    expect(fn () => $p->parse(json_encode(['type' => 'invoice.paid', 'data' => ['object' => []]])))->toThrow(InvalidArgumentException::class);
    $neg = $p->parse(json_encode(['id' => 'e', 'type' => 'invoice.paid', 'data' => ['object' => ['amount_paid' => -5, 'currency' => 'dollars']]]));
    expect([$neg->amountMinor, $neg->currency])->toBe([null, null]);
});

it('verifies and parses Paystack events', function () {
    $p = new PaystackProcessor('sk_test', ['pro.ZAR.monthly' => 'PLN_1']);
    $body = json_encode(['event' => 'charge.success', 'data' => ['id' => 77, 'amount' => 69900, 'currency' => 'ZAR', 'customer' => ['customer_code' => 'CUS_1'], 'metadata' => ['tenant_id' => BP_TENANT]]]);
    $sig = hash_hmac('sha512', $body, 'sk_test');

    expect($p->verify($body, ['X-Paystack-Signature' => $sig]))->toBeTrue();
    expect($p->verify($body.'x', ['X-Paystack-Signature' => $sig]))->toBeFalse();
    expect($p->verify($body, ['X-Paystack-Signature' => strtoupper($sig)]))->toBeFalse();
    expect($p->verify($body, []))->toBeFalse();
    expect((new PaystackProcessor('', []))->verify($body, ['X-Paystack-Signature' => hash_hmac('sha512', $body, '')]))->toBeFalse();

    $e = $p->parse($body);
    expect([$e->type, $e->reference, $e->amountMinor, $e->currency, $e->tenantId, $e->invoiceRef])->toBe([BillingEvent::RENEWAL_SUCCEEDED, 'CUS_1', 69900, 'ZAR', BP_TENANT, '77']);
    expect($e->eventId)->toBe('sha256:'.hash('sha256', $body));

    $created = $p->parse(json_encode(['event' => 'subscription.create', 'data' => ['plan' => ['plan_code' => 'PLN_1'], 'customer' => ['customer_code' => 'CUS_1']]]));
    expect([$created->type, $created->planKey, $created->cycle])->toBe([BillingEvent::PLAN_CHANGED, 'pro', 'monthly']);
    expect($p->parse(json_encode(['event' => 'subscription.create', 'data' => ['plan' => ['plan_code' => 'PLN_UNKNOWN']]])))->toBeNull();
    expect($p->parse(json_encode(['event' => 'transfer.success', 'data' => []])))->toBeNull();
});

it('verifies Chargebee basic credentials and parses events', function () {
    $p = new ChargebeeProcessor('hook', 'secret-pass');
    $ok = 'Basic '.base64_encode('hook:secret-pass');

    expect($p->verify('{}', ['Authorization' => $ok]))->toBeTrue();
    expect($p->verify('{}', ['Authorization' => 'Basic '.base64_encode('hook:wrong')]))->toBeFalse();
    expect($p->verify('{}', ['Authorization' => 'Bearer x']))->toBeFalse();
    expect($p->verify('{}', ['Authorization' => 'Basic !!!']))->toBeFalse();
    expect($p->verify('{}', []))->toBeFalse();
    expect((new ChargebeeProcessor('', ''))->verify('{}', ['Authorization' => 'Basic '.base64_encode(':')]))->toBeFalse();

    $e = $p->parse(json_encode(['id' => 'ev_1', 'event_type' => 'payment_succeeded', 'content' => [
        'subscription' => ['id' => 'sub_c', 'meta_data' => ['tenant_id' => BP_TENANT]], 'transaction' => ['amount' => 1500, 'currency_code' => 'usd'], 'invoice' => ['id' => 'inv_c'],
    ]]));
    expect([$e->type, $e->reference, $e->amountMinor, $e->currency, $e->invoiceRef])->toBe([BillingEvent::RENEWAL_SUCCEEDED, 'sub_c', 1500, 'USD', 'inv_c']);
    expect($p->parse(json_encode(['id' => 'ev_2', 'event_type' => 'payment_failed', 'content' => ['subscription' => ['id' => 'sub_c']]]))->type)->toBe(BillingEvent::PAYMENT_FAILED);
    expect($p->parse(json_encode(['id' => 'ev_3', 'event_type' => 'customer_created', 'content' => []])))->toBeNull();
});

it('moves subscription status through the state machine and keeps cancelled final', function () {
    expect(SubscriptionState::after('active', BillingEvent::PAYMENT_FAILED))->toBe('past_due');
    expect(SubscriptionState::after('past_due', BillingEvent::PAYMENT_FAILED))->toBe('past_due');
    expect(SubscriptionState::after('past_due', BillingEvent::RENEWAL_SUCCEEDED))->toBe('active');
    expect(SubscriptionState::after('active', BillingEvent::CANCELLED))->toBe('cancelled');
    expect(SubscriptionState::after('cancelled', BillingEvent::RENEWAL_SUCCEEDED))->toBe('cancelled');
    expect(SubscriptionState::after('active', BillingEvent::PLAN_CHANGED))->toBe('active');
});

it('computes grace expiry and period end', function () {
    $since = new DateTimeImmutable('2026-10-01 00:00:00 UTC');
    expect(SubscriptionState::graceExpired($since, new DateTimeImmutable('2026-10-07 23:59:59 UTC'), 7))->toBeFalse();
    expect(SubscriptionState::graceExpired($since, new DateTimeImmutable('2026-10-08 00:00:00 UTC'), 7))->toBeTrue();
    expect(SubscriptionState::periodEnd($since, 'annual')->format('Y-m-d'))->toBe('2027-10-01');
    expect(SubscriptionState::periodEnd($since, 'monthly')->format('Y-m-d'))->toBe('2026-11-01');
});
