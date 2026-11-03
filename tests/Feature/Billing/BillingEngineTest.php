<?php

use App\Kernel\Contracts\EmailSender;
use App\Kernel\Dtos\NotificationRequested;
use App\Kernel\Dtos\OutboundEmailDTO;
use App\Platform\Billing\BillingWebhookHandler;
use App\Platform\Billing\ProcessorRegistry;
use App\Platform\Modules\ModuleManager;
use App\Platform\Registry\TenantRegistry;
use App\Platform\Subscriptions\SubscriptionManagement;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Modules\Core\Models\User;
use Tests\TestCase;

require_once __DIR__.'/../Core/CoreTestHelpers.php';
require_once __DIR__.'/../Core/CoreHttpHelpers.php';

uses(TestCase::class, DatabaseTransactions::class);

const BW_URL = 'http://admin.platform.test/api/control-plane/billing/webhook';
const BW_SECRET = 'whsec_test';

beforeEach(function () {
    config(['billing.processors.stripe.webhook_secret' => BW_SECRET, 'billing.grace_days' => 7]);
    app()->instance(EmailSender::class, new class implements EmailSender {
        public function send(OutboundEmailDTO $e): void
        {
        }
    });
});

/** POST a correctly signed Stripe webhook (or a tampered one). */
function bwPost(array $event, ?string $secret = BW_SECRET, string $url = BW_URL.'?processor=stripe')
{
    $body = json_encode($event);
    $t = time();
    $server = ['CONTENT_TYPE' => 'application/json'];
    if ($secret !== null) {
        $server['HTTP_STRIPE_SIGNATURE'] = "t={$t},v1=".hash_hmac('sha256', "{$t}.{$body}", $secret);
    }

    return test()->call('POST', $url, [], [], [], $server, $body);
}

function bwSub(string $tenantId): object
{
    return DB::table('tenant_subscriptions')->where('tenant_id', $tenantId)->first();
}

/** Tenant bound to a Stripe subscription 'sub_<x>'. */
function bwTenant(string $sub): array
{
    [$id, $admin] = coreHttpTenant($sub);
    DB::table('tenant_subscriptions')->where('tenant_id', $id)->update(['processor' => 'stripe', 'processor_reference' => "sub_{$sub}"]);

    return [$id, $admin];
}

function bwInvoiceEvent(string $type, string $sub, string $tenantId, string $eventId, int $amount = 69900): array
{
    return ['id' => $eventId, 'type' => $type, 'data' => ['object' => [
        'id' => 'in_'.$eventId, 'subscription' => "sub_{$sub}", 'amount_paid' => $amount, 'currency' => 'zar',
        'subscription_details' => ['metadata' => ['tenant_id' => $tenantId]],
        'lines' => ['data' => [['period' => ['start' => time(), 'end' => time() + 2_592_000]]]],
    ]]];
}

it('rejects a missing, wrong or unknown-processor signature before anything changes', function () {
    [$id] = bwTenant('sig');
    $ev = bwInvoiceEvent('invoice.payment_failed', 'sig', $id, 'evt_a');

    bwPost($ev, null)->assertStatus(400)->assertExactJson(['Invalid webhook signature']);
    bwPost($ev, 'wrong')->assertStatus(400);
    bwPost($ev, BW_SECRET, BW_URL.'?processor=nope')->assertStatus(400);
    bwPost($ev, BW_SECRET, BW_URL)->assertStatus(400);
    config(['billing.processors.stripe.webhook_secret' => '']);
    bwPost($ev, '')->assertStatus(400);

    expect(bwSub($id)->status)->toBe('active')->and(DB::table('billing_events')->count())->toBe(0);
});

it('serves the webhook on the admin host only', function () {
    [$id] = bwTenant('host');
    bwPost(bwInvoiceEvent('invoice.payment_failed', 'host', $id, 'evt_h'), BW_SECRET, 'http://host.platform.test/api/control-plane/billing/webhook?processor=stripe')->assertNotFound();
    bwPost(bwInvoiceEvent('invoice.payment_failed', 'host', $id, 'evt_h'), BW_SECRET, 'http://platform.test/api/control-plane/billing/webhook?processor=stripe')->assertNotFound();
});

it('returns 404 for an authentic event that matches no tenant', function () {
    bwPost(bwInvoiceEvent('invoice.paid', 'ghost', (string) fake()->uuid(), 'evt_g'))->assertStatus(404)->assertExactJson(['No matching tenant found for this billing event']);
    expect(DB::table('billing_events')->count())->toBe(0);
});

it('acknowledges but ignores event types it does not act on', function () {
    bwPost(['id' => 'evt_i', 'type' => 'customer.created', 'data' => ['object' => []]])->assertOk()->assertJson(['ignored' => true]);
});

it('marks a tenant past due on a failed renewal, notifies admins and keeps access', function () {
    [$id] = bwTenant('late');
    Event::fake([NotificationRequested::class]);

    bwPost(bwInvoiceEvent('invoice.payment_failed', 'late', $id, 'evt_f1'))->assertOk()->assertJson(['tenant_id' => $id, 'subscription_status' => 'past_due']);

    expect(bwSub($id)->status)->toBe('past_due')->and(bwSub($id)->past_due_since)->not->toBeNull();
    expect(DB::table('tenants')->where('id', $id)->value('status'))->toBe('active');
    Event::assertDispatched(NotificationRequested::class, fn ($e) => $e->payload()->type === 'billing.payment_failed');
    $this->get(coreHost('late', '/login'))->assertOk();   // still works during grace
});

it('is idempotent: a retried event does not repeat its effects', function () {
    [$id] = bwTenant('retry');
    Event::fake([NotificationRequested::class]);
    $ev = bwInvoiceEvent('invoice.payment_failed', 'retry', $id, 'evt_r');
    bwPost($ev)->assertOk();
    bwPost($ev)->assertOk()->assertJson(['subscription_status' => 'past_due']);
    Event::assertDispatchedTimes(NotificationRequested::class, 1);

    $paid = bwInvoiceEvent('invoice.paid', 'retry', $id, 'evt_r2');
    bwPost($paid)->assertOk();
    bwPost($paid)->assertOk();
    expect(DB::table('platform_invoices')->where('tenant_id', $id)->count())->toBe(1);
});

it('records the operator invoice and restores active status when a renewal succeeds', function () {
    [$id] = bwTenant('paid');
    bwPost(bwInvoiceEvent('invoice.payment_failed', 'paid', $id, 'evt_p1'))->assertOk();
    bwPost(bwInvoiceEvent('invoice.paid', 'paid', $id, 'evt_p2'))->assertOk()->assertJson(['subscription_status' => 'active']);

    $s = bwSub($id);
    expect($s->status)->toBe('active')->and($s->past_due_since)->toBeNull();
    $inv = DB::table('platform_invoices')->where('tenant_id', $id)->first();
    expect($inv->amount_minor)->toBe(69900)->and($inv->currency)->toBe('ZAR')->and($inv->plan_key)->toBe('professional')->and($inv->number)->toMatch('/^INV-\d{4}-\d{6}$/');
});

it('suspends after the grace period and reinstates automatically when payment arrives', function () {
    [$id] = bwTenant('grace');
    bwPost(bwInvoiceEvent('invoice.payment_failed', 'grace', $id, 'evt_g1'))->assertOk();

    $this->artisan('billing:enforce-past-due')->assertSuccessful();
    expect(DB::table('tenants')->where('id', $id)->value('status'))->toBe('active');   // inside grace

    DB::table('tenant_subscriptions')->where('tenant_id', $id)->update(['past_due_since' => now()->subDays(8)]);
    $this->artisan('billing:enforce-past-due')->assertSuccessful();
    expect(DB::table('tenants')->where('id', $id)->value('status'))->toBe('suspended');
    $ev = DB::table('tenant_status_events')->where('tenant_id', $id)->first();
    expect([$ev->actor, $ev->operator_id])->toBe(['system', null])->and($ev->reason)->toStartWith('Billing: ');

    bwPost(bwInvoiceEvent('invoice.paid', 'grace', $id, 'evt_g2'))->assertOk();
    expect(DB::table('tenants')->where('id', $id)->value('status'))->toBe('active');
});

it('does not reinstate an operator suspension on payment', function () {
    [$id] = bwTenant('opkeep');
    $op = DB::table('operators')->insertGetId(['name' => 'O', 'email' => 'o@platform.test', 'password' => 'x', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    app(TenantRegistry::class)->changeStatus($id, 'suspended', 'Abuse report', $op);
    bwPost(bwInvoiceEvent('invoice.paid', 'opkeep', $id, 'evt_o1'))->assertOk();
    expect(DB::table('tenants')->where('id', $id)->value('status'))->toBe('suspended');
});

it('suspends a tenant when the processor cancels the subscription, keeping all data', function () {
    [$id] = bwTenant('gone');
    bwPost(['id' => 'evt_c', 'type' => 'customer.subscription.deleted', 'data' => ['object' => ['id' => 'sub_gone', 'metadata' => ['tenant_id' => $id]]]])
        ->assertOk()->assertJson(['subscription_status' => 'cancelled']);
    expect(DB::table('tenants')->where('id', $id)->value('status'))->toBe('suspended');
    expect(DB::table('users')->where('email', 'gone@example.com')->exists())->toBeTrue();
    bwPost(bwInvoiceEvent('invoice.paid', 'gone', $id, 'evt_c2'))->assertOk()->assertJson(['subscription_status' => 'cancelled']);   // cancelled is final
});

it('will not let metadata steer a tenant bound to another processor or reference', function () {
    [$id] = bwTenant('bound');
    DB::table('tenant_subscriptions')->where('tenant_id', $id)->update(['processor' => 'paystack', 'processor_reference' => 'CUS_1']);
    bwPost(bwInvoiceEvent('invoice.payment_failed', 'attacker', $id, 'evt_x'))->assertStatus(404);

    DB::table('tenant_subscriptions')->where('tenant_id', $id)->update(['processor' => 'stripe', 'processor_reference' => 'sub_real']);
    bwPost(bwInvoiceEvent('invoice.payment_failed', 'other', $id, 'evt_y'))->assertStatus(404);
    expect(bwSub($id)->status)->toBe('active');
});

it('adopts the processor reference on first contact', function () {
    [$id] = coreHttpTenant('adopt');
    bwPost(bwInvoiceEvent('invoice.paid', 'adopt', $id, 'evt_ad'))->assertOk();
    expect([bwSub($id)->processor, bwSub($id)->processor_reference])->toBe(['stripe', 'sub_adopt']);
});

it('applies a processor-confirmed plan change and keeps data when a module is dropped', function () {
    [$id] = bwTenant('down');
    $starter = DB::table('plans')->where('key', 'starter')->value('id');
    DB::table('plan_modules')->where('plan_id', $starter)->where('module_key', 'Support')->delete();   // starter no longer includes Support
    app(ModuleManager::class)->enable($id, 'Support');
    DB::table('tenant_domains')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $id, 'domain' => 'shop.down.test', 'verification_token' => 't', 'cname_target' => 'c', 'verified_at' => now(), 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    Event::fake([NotificationRequested::class]);

    bwPost(['id' => 'evt_d', 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_down', 'metadata' => ['tenant_id' => $id, 'plan' => 'starter', 'billing_cycle' => 'monthly']]]])->assertOk();

    expect((int) bwSub($id)->plan_id)->toBe((int) $starter);
    expect(app(ModuleManager::class)->activeModules($id))->not->toContain('Support')->toContain('Core');
    expect(DB::table('tenant_modules')->where('tenant_id', $id)->where('module_key', 'Support')->exists())->toBeTrue();   // record kept
    expect(DB::table('tenant_domains')->where('domain', 'shop.down.test')->value('is_active'))->toBeFalse()->and(DB::table('tenant_domains')->where('domain', 'shop.down.test')->exists())->toBeTrue();
    Event::assertDispatched(NotificationRequested::class, fn ($e) => $e->payload()->type === 'billing.plan_changed');
    $this->get(coreHost('down', '/login'))->assertOk();   // platform subdomain keeps working
});

it('refuses a downgrade while there are too many active users and says how many to deactivate', function () {
    [$id] = bwTenant('seats');
    foreach (['a', 'b'] as $x) {
        User::create(['name' => $x, 'email' => "{$x}@seats.example.com", 'password' => 'Str0ng-password!', 'is_active' => true]);
    }   // 3 active users incl. the admin; starter allows 2
    Event::fake([NotificationRequested::class]);

    $check = app(SubscriptionManagement::class)->check($id, 'starter', 'monthly');
    expect($check->ok)->toBeFalse()->and($check->usersToDeactivate)->toBe(1)->and($check->reason)->toContain('Deactivate 1 user');

    bwPost(['id' => 'evt_s', 'type' => 'customer.subscription.updated', 'data' => ['object' => ['id' => 'sub_seats', 'metadata' => ['tenant_id' => $id, 'plan' => 'starter', 'billing_cycle' => 'monthly']]]])->assertOk();
    expect(bwSub($id)->plan_id)->not->toBe(DB::table('plans')->where('key', 'starter')->value('id'));
    expect(DB::table('billing_events')->where('event_id', 'evt_s')->value('outcome'))->toBe('rejected_seats');
    Event::assertDispatched(NotificationRequested::class, fn ($e) => $e->payload()->type === 'billing.plan_change_blocked');
});

it('rejects an unknown plan or cycle in a plan change without touching the subscription', function () {
    [$id] = bwTenant('badplan');
    $m = app(SubscriptionManagement::class);
    expect($m->check($id, 'platinum', 'monthly')->ok)->toBeFalse()->and($m->check($id, 'starter', 'weekly')->ok)->toBeFalse();
});

it('creates the processor subscription after provisioning and stores the reference', function () {
    config(['billing.processor_for_currency.ZAR' => 'stripe', 'billing.processors.stripe.api_key' => 'sk_test']);
    Http::fake([
        'api.stripe.com/v1/customers' => Http::response(['id' => 'cus_1']),
        'api.stripe.com/v1/subscriptions' => Http::response(['id' => 'sub_new']),
    ]);
    [$id] = coreHttpTenant('prov');

    expect([bwSub($id)->processor, bwSub($id)->processor_reference])->toBe(['stripe', 'sub_new']);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/subscriptions') && ($r['metadata']['tenant_id'] ?? null) === $id && ($r['items'][0]['price_data']['unit_amount'] ?? null) === 69900 && $r['items'][0]['price_data']['currency'] === 'zar');
});

it('keeps the tenant and plan when the processor is down during signup', function () {
    config(['billing.processor_for_currency.ZAR' => 'stripe', 'billing.processors.stripe.api_key' => 'sk_test']);
    Http::fake(['api.stripe.com/*' => Http::response(['error' => 'down'], 500)]);
    [$id] = coreHttpTenant('prov2');
    expect(DB::table('tenants')->where('id', $id)->exists())->toBeTrue()->and(bwSub($id)->processor_reference)->toBeNull();
});

it('lets an operator with billing permission see invoices, and hides them otherwise', function () {
    [$id] = bwTenant('inv');
    bwPost(bwInvoiceEvent('invoice.paid', 'inv', $id, 'evt_inv'))->assertOk();
    $op = App\Platform\ControlPlane\Operator::create(['name' => 'O', 'email' => 'o2@platform.test', 'password' => 'Operator-pass-123', 'is_active' => true]);
    DB::table('operator_permissions')->insert([['operator_id' => $op->id, 'permission' => 'control_plane.tenants.view']]);
    $this->actingAs($op, 'operator');
    $this->getJson("http://admin.platform.test/tenants/{$id}")->assertOk()->assertJsonPath('can.billing', false)->assertJsonCount(0, 'invoices');
    DB::table('operator_permissions')->insert(['operator_id' => $op->id, 'permission' => 'control_plane.billing.view']);
    $op->refresh();
    $this->actingAs(App\Platform\ControlPlane\Operator::find($op->id), 'operator');
    $this->getJson("http://admin.platform.test/tenants/{$id}")->assertOk()->assertJsonPath('can.billing', true)->assertJsonCount(1, 'invoices');
});

it('rejects an invalid subscription status at the database', function () {
    [$id] = bwTenant('chk');
    expect(fn () => coreInSavepoint(fn () => DB::table('tenant_subscriptions')->where('tenant_id', $id)->update(['status' => 'bogus'])))->toThrow(Illuminate\Database\QueryException::class);
});
