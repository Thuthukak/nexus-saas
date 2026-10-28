<?php

use App\Kernel\Contracts\EmailSender;
use App\Kernel\Dtos\OutboundEmailDTO;
use App\Kernel\Dtos\TenantStatusChanged;
use App\Platform\ControlPlane\Operator;
use App\Platform\Registry\InvalidStatusTransition;
use App\Platform\Registry\TenantRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

require_once __DIR__.'/../Core/CoreTestHelpers.php';
require_once __DIR__.'/../Core/CoreHttpHelpers.php';

uses(TestCase::class, DatabaseTransactions::class);

const CP_ADMIN = 'http://admin.platform.test';

function cpOperator(array $perms = ['control_plane.*'], bool $active = true, string $email = 'ops@platform.test'): Operator
{
    $op = Operator::create(['name' => 'Olive', 'email' => $email, 'password' => 'Operator-pass-123', 'is_active' => $active]);
    foreach ($perms as $p) {
        DB::table('operator_permissions')->insert(['operator_id' => $op->id, 'permission' => $p]);
    }

    return $op;
}

function cpLogin(Operator $op): void
{
    test()->actingAs($op, 'operator');
}

/** Captures outbound mail instead of sending. */
function cpCaptureMail(): object
{
    $spy = new class implements EmailSender {
        public array $sent = [];

        public function send(OutboundEmailDTO $e): void
        {
            $this->sent[] = $e;
        }
    };
    app()->instance(EmailSender::class, $spy);

    return $spy;
}

it('signs an operator in on the admin host and refuses wrong passwords generically', function () {
    cpOperator();
    $this->post(CP_ADMIN.'/login', ['email' => 'OPS@platform.test', 'password' => 'nope'])->assertSessionHasErrors('email');
    $this->post(CP_ADMIN.'/login', ['email' => 'ops@platform.test', 'password' => 'Operator-pass-123'])->assertRedirect('/tenants');
    $this->assertAuthenticated('operator');
});

it('refuses a deactivated operator and a tenant user on the admin host', function () {
    cpOperator(active: false, email: 'gone@platform.test');
    $this->post(CP_ADMIN.'/login', ['email' => 'gone@platform.test', 'password' => 'Operator-pass-123'])->assertSessionHasErrors('email');

    [$tenantId] = coreHttpTenant('cpa');
    $this->post(CP_ADMIN.'/login', ['email' => 'cpa@example.com', 'password' => 'Str0ng-password!'])->assertSessionHasErrors('email');
});

it('requires sign-in for control plane data and signs out a deactivated operator', function () {
    $this->getJson(CP_ADMIN.'/tenants')->assertStatus(401);
    $op = cpOperator();
    cpLogin($op);
    $this->getJson(CP_ADMIN.'/tenants')->assertOk();
    DB::table('operators')->where('id', $op->id)->update(['is_active' => false]);
    app('auth')->forgetGuards();
    $this->getJson(CP_ADMIN.'/tenants')->assertStatus(401);
});

it('does not serve control plane routes on a tenant host', function () {
    coreHttpTenant('cpb');
    cpLogin(cpOperator());
    $this->getJson(coreHost('cpb', '/tenants'))->assertNotFound();
});

it('lists, searches and filters tenants', function () {
    coreHttpTenant('alpha');
    coreHttpTenant('bravo');
    cpLogin(cpOperator());
    $names = fn ($r) => collect($r->json('data'))->pluck('subdomain')->all();
    expect($names($this->getJson(CP_ADMIN.'/tenants?q=alph')))->toBe(['alpha']);
    expect($names($this->getJson(CP_ADMIN.'/tenants?q=%25')))->toBe([]);   // wildcard characters are literal
    expect($names($this->getJson(CP_ADMIN.'/tenants?status=suspended')))->toBe([]);
    expect($names($this->getJson(CP_ADMIN.'/tenants?status=active')))->toContain('alpha', 'bravo');
});

it('enforces per-action permissions', function () {
    [$id] = coreHttpTenant('perm');
    cpLogin(cpOperator(['control_plane.tenants.view']));
    $this->getJson(CP_ADMIN."/tenants/{$id}")->assertOk()->assertJsonPath('can.suspend', false);
    $this->postJson(CP_ADMIN."/tenants/{$id}/suspend", ['reason' => 'x'])->assertForbidden();
    expect(DB::table('tenants')->where('id', $id)->value('status'))->toBe('active');
});

it('suspends a tenant immediately, audits it, notifies admins and blocks the workspace', function () {
    [$id] = coreHttpTenant('susp');
    $mail = cpCaptureMail();
    Event::fake([TenantStatusChanged::class]);
    $op = cpOperator();
    cpLogin($op);

    $this->postJson(CP_ADMIN."/tenants/{$id}/suspend", ['reason' => 'Unpaid invoices'])->assertOk()->assertJsonPath('status', 'suspended');

    expect(DB::table('tenants')->where('id', $id)->value('status'))->toBe('suspended');
    $ev = DB::table('tenant_status_events')->where('tenant_id', $id)->first();
    expect([$ev->from_status, $ev->to_status, $ev->actor, $ev->operator_id, $ev->reason])->toBe(['active', 'suspended', 'operator', $op->id, 'Unpaid invoices']);
    Event::assertDispatched(TenantStatusChanged::class, fn ($e) => $e->payload()->toStatus === 'suspended');
    expect($mail->sent)->toHaveCount(1)->and($mail->sent[0]->toAddress)->toBe('susp@example.com')->and($mail->sent[0]->textBody)->toContain('Unpaid invoices');

    $this->get(coreHost('susp', '/login'))->assertStatus(403);
    $this->assertDatabaseHas('tenants', ['id' => $id, 'status' => 'suspended']);
});

it('is idempotent: suspending twice audits and emails once', function () {
    [$id] = coreHttpTenant('twice');
    $mail = cpCaptureMail();
    cpLogin(cpOperator());
    $this->postJson(CP_ADMIN."/tenants/{$id}/suspend", ['reason' => 'a'])->assertOk();
    $this->postJson(CP_ADMIN."/tenants/{$id}/suspend", ['reason' => 'b'])->assertOk();
    expect(DB::table('tenant_status_events')->where('tenant_id', $id)->count())->toBe(1)->and($mail->sent)->toHaveCount(1);
});

it('reinstates a suspended tenant and lets the workspace answer again', function () {
    [$id] = coreHttpTenant('back');
    cpCaptureMail();
    cpLogin(cpOperator());
    $this->postJson(CP_ADMIN."/tenants/{$id}/suspend", ['reason' => 'Dispute'])->assertOk();
    $this->postJson(CP_ADMIN."/tenants/{$id}/reinstate", ['reason' => 'Resolved'])->assertOk()->assertJsonPath('status', 'active');
    $this->get(coreHost('back', '/login'))->assertOk();
    expect(DB::table('tenant_status_events')->where('tenant_id', $id)->count())->toBe(2);
});

it('requires a reason and refuses unknown tenants and cancelled ones', function () {
    [$id] = coreHttpTenant('edge');
    cpCaptureMail();
    cpLogin(cpOperator());
    $this->postJson(CP_ADMIN."/tenants/{$id}/suspend", ['reason' => '   '])->assertStatus(422);
    $this->postJson(CP_ADMIN."/tenants/{$id}/suspend", [])->assertStatus(422);
    $this->postJson(CP_ADMIN.'/tenants/not-a-uuid/suspend', ['reason' => 'x'])->assertNotFound();
    $this->postJson(CP_ADMIN.'/tenants/'.fake()->uuid().'/suspend', ['reason' => 'x'])->assertNotFound();

    app(TenantRegistry::class)->changeStatus($id, 'cancelled', 'Closed');
    $this->postJson(CP_ADMIN."/tenants/{$id}/reinstate", ['reason' => 'x'])->assertStatus(409);
});

it('refuses illegal transitions in the registry and never deletes data', function () {
    [$id] = coreHttpTenant('keep');
    cpCaptureMail();
    app(TenantRegistry::class)->changeStatus($id, 'cancelled', 'Closed');
    expect(fn () => app(TenantRegistry::class)->changeStatus($id, 'active', 'Oops'))->toThrow(InvalidStatusTransition::class);
    expect(DB::table('tenants')->where('id', $id)->exists())->toBeTrue()
        ->and(DB::table('users')->where('email', 'keep@example.com')->exists())->toBeTrue();
});

it('lets the platform itself change status without an operator (billing engine path)', function () {
    [$id] = coreHttpTenant('sys');
    cpCaptureMail();
    app(TenantRegistry::class)->changeStatus($id, 'suspended', 'Payment failed');
    $ev = DB::table('tenant_status_events')->where('tenant_id', $id)->first();
    expect([$ev->actor, $ev->operator_id])->toBe(['system', null]);
});

it('does not undo the status change when a notification email fails', function () {
    [$id] = coreHttpTenant('mailfail');
    app()->instance(EmailSender::class, new class implements EmailSender {
        public function send(OutboundEmailDTO $e): void
        {
            throw new RuntimeException('smtp down');
        }
    });
    app(TenantRegistry::class)->changeStatus($id, 'suspended', 'x');
    expect(DB::table('tenants')->where('id', $id)->value('status'))->toBe('suspended');
});

it('rejects an invalid status value at the database', function () {
    [$id] = coreHttpTenant('chk');
    expect(fn () => coreInSavepoint(fn () => DB::table('tenants')->where('id', $id)->update(['status' => 'bogus'])))->toThrow(Illuminate\Database\QueryException::class);
});

it('creates operators from the console with validation', function () {
    $this->artisan('operators:create', ['name' => 'Sam', 'email' => 'sam@platform.test', '--permission' => ['control_plane.tenants.view'], '--password' => 'Long-enough-pass1'])->assertSuccessful();
    expect(Operator::where('email', 'sam@platform.test')->firstOrFail()->hasPermission('control_plane.tenants.view'))->toBeTrue();
    $this->artisan('operators:create', ['name' => 'S', 'email' => 'SAM@platform.test', '--permission' => ['control_plane.*'], '--password' => 'Long-enough-pass1'])->assertFailed();
    $this->artisan('operators:create', ['name' => 'T', 'email' => 't@platform.test', '--permission' => ['tenants.*'], '--password' => 'Long-enough-pass1'])->assertFailed();
    $this->artisan('operators:create', ['name' => 'T', 'email' => 't@platform.test', '--permission' => ['control_plane.*'], '--password' => 'short'])->assertFailed();
});
