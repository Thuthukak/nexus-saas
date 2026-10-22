<?php

use App\Kernel\Contracts\ProvisionsTenantAdmin;
use App\Kernel\Dtos\ProvisionTenantAdminDTO;
use App\Kernel\Dtos\TenantAdminProvisionedDTO;
use App\Platform\Onboarding\Mail\WelcomeMail;
use App\Tenancy\TenantContext;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Models\User;
use Modules\Core\Usage\SeatLimitReached;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

require_once __DIR__.'/../Core/CoreTestHelpers.php';

/*
| Requires phpunit.xml to set TENANCY_ROOT_DOMAIN=platform.test (see SETUP-4.md), because the
| signup route is registered for the root domain when the application boots.
*/
uses(TestCase::class, DatabaseTransactions::class);

function onbPayload(array $override = []): array
{
    return array_merge([
        'company_name' => 'Acme Trading',
        'admin_name' => 'Ada Admin',
        'admin_email' => 'ada@example.com',
        'admin_password' => 'Str0ng-password!',
        'requested_subdomain' => 'acme',
        'business_type' => 'retail',
        'subscription_plan' => 'starter',
        'billing_cycle' => 'monthly',
        'country_code' => 'ZA',
    ], $override);
}

function onbSignup(array $override = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson('http://platform.test/api/tenants', onbPayload($override));
}

beforeEach(function () {
    if (DB::table('plans')->count() === 0) {
        (new PlanCatalogueSeeder())->run();
    }
    Mail::fake();
});

it('provisions a tenant, subscription, modules, admin, seat and handoff in one go', function () {
    $response = onbSignup()->assertCreated()->assertJsonStructure([
        'tenant_id', 'subdomain', 'workspace_url', 'handoff_url', 'billing_currency', 'subscription_plan',
    ]);

    $json = $response->json();
    expect($json['subdomain'])->toBe('acme')
        ->and($json['billing_currency'])->toBe('ZAR')
        ->and($json['subscription_plan'])->toBe('starter')
        ->and($json['workspace_url'])->toStartWith('http://acme.platform.test')
        ->and($json['handoff_url'])->toStartWith('http://acme.platform.test/auth/handoff?');

    $id = $json['tenant_id'];
    $tenant = DB::table('tenants')->where('id', $id)->first();
    expect($tenant->status)->toBe('active')->and($tenant->business_type)->toBe('retail')->and($tenant->country_code)->toBe('ZA');

    $sub = DB::table('tenant_subscriptions')->where('tenant_id', $id)->first();
    expect($sub->status)->toBe('active')->and($sub->billing_cycle)->toBe('monthly')->and($sub->currency)->toBe('ZAR');

    expect(DB::table('tenant_modules')->where('tenant_id', $id)->where('is_enabled', true)->orderBy('module_key')->pluck('module_key')->all())
        ->toBe(['Core', 'Financial', 'Support']);

    expect(DB::table('platform_emails')->where('email_normalised', 'ada@example.com')->value('tenant_id'))->toBe($id)
        ->and(DB::table('login_handoffs')->where('tenant_id', $id)->count())->toBe(1);

    // Tenant-scoped data needs the tenant context.
    app(TenantContext::class)->set($id);
    $admin = User::where('email', 'ada@example.com')->firstOrFail();
    expect($admin->is_active)->toBeTrue()
        ->and($admin->hasRole('Tenant Admin'))->toBeTrue()
        ->and($admin->hasRole('Super Admin'))->toBeFalse()
        ->and(Role::pluck('name')->sort()->values()->all())->toBe(['Super Admin', 'Tenant Admin'])
        ->and(DB::table('usage_counters')->where(['metric' => 'user_seats'])->value('quantity'))->toBe(1);
});

it('never stores or returns the plain password', function () {
    $response = onbSignup();

    expect($response->getContent())->not->toContain('Str0ng-password!');
    $id = $response->json('tenant_id');
    app(TenantContext::class)->set($id);
    expect(User::where('email', 'ada@example.com')->value('password'))->not->toBe('Str0ng-password!')
        ->and(password_verify('Str0ng-password!', User::where('email', 'ada@example.com')->value('password')))->toBeTrue();
});

it('queues the welcome email with the workspace address and without any sign-in link or password', function () {
    onbSignup()->assertCreated();

    Mail::assertQueued(WelcomeMail::class, function (WelcomeMail $mail) {
        return $mail->hasTo('ada@example.com')
            && $mail->workspaceUrl === 'http://acme.platform.test/'
            && ! str_contains($mail->render(), 'handoff')
            && ! str_contains($mail->render(), 'Str0ng-password!');
    });
});

it('bills South Africa in ZAR and every other country in USD', function (string $country, string $currency) {
    onbSignup(['country_code' => $country, 'requested_subdomain' => 'cur-'.strtolower($country), 'admin_email' => "u-{$country}@example.com"])
        ->assertCreated()->assertJsonPath('billing_currency', $currency);
})->with([['ZA', 'ZAR'], ['za', 'ZAR'], ['US', 'USD'], ['NA', 'USD'], ['GB', 'USD']]);

it('supports the annual cycle and every plan', function (string $plan, string $cycle) {
    $r = onbSignup(['subscription_plan' => $plan, 'billing_cycle' => $cycle, 'requested_subdomain' => "p-{$plan}-{$cycle}", 'admin_email' => "{$plan}-{$cycle}@example.com"])
        ->assertCreated();

    $sub = DB::table('tenant_subscriptions')->where('tenant_id', $r->json('tenant_id'))->first();
    expect($sub->billing_cycle)->toBe($cycle);
})->with([['starter', 'annual'], ['professional', 'monthly'], ['enterprise', 'annual']]);

// ---- uniqueness: complete email, never the domain ----

it('rejects a duplicate COMPLETE email address, whatever its casing, with 409', function () {
    onbSignup()->assertCreated();

    onbSignup(['admin_email' => 'ADA@Example.com', 'requested_subdomain' => 'other'])
        ->assertStatus(409)->assertExactJson(['message' => 'A tenant with this subdomain or email address already exists']);

    expect(DB::table('tenants')->where('subdomain', 'other')->count())->toBe(0);
});

it('lets many people on the same email domain, including gmail.com, each register their own tenant', function () {
    onbSignup(['admin_email' => 'sam@gmail.com', 'requested_subdomain' => 'sam-co'])->assertCreated();
    onbSignup(['admin_email' => 'alex@gmail.com', 'requested_subdomain' => 'alex-co'])->assertCreated();
    onbSignup(['admin_email' => 'jo@example.com', 'requested_subdomain' => 'jo-co'])->assertCreated();
    onbSignup(['admin_email' => 'pat@example.com', 'requested_subdomain' => 'pat-co'])->assertCreated();

    expect(DB::table('platform_emails')->count())->toBe(4);
});

// ---- subdomain rules ----

it('rejects a taken subdomain with 409', function () {
    onbSignup()->assertCreated();

    onbSignup(['admin_email' => 'second@example.com'])->assertStatus(409);
});

it('rejects reserved subdomains with 409', function (string $reserved) {
    onbSignup(['requested_subdomain' => $reserved])->assertStatus(409);
    expect(DB::table('tenants')->where('subdomain', $reserved)->count())->toBe(0);
})->with(['www', 'api', 'admin', 'app', 'mail', 'reply', 'control', 'status', 'ADMIN']);

it('normalises the requested subdomain and falls back to the company name when blank', function () {
    onbSignup(['requested_subdomain' => 'My Shop!!', 'admin_email' => 'a@example.com'])->assertCreated()->assertJsonPath('subdomain', 'my-shop');
    onbSignup(['requested_subdomain' => '', 'company_name' => 'Penda Graphics (Pty) Ltd', 'admin_email' => 'b@example.com'])
        ->assertCreated()->assertJsonPath('subdomain', 'penda-graphics-pty-ltd');
});

// ---- validation ----

it('returns 422 with "Validation failed" for bad input', function (array $override, string $field) {
    onbSignup($override)->assertStatus(422)->assertJsonPath('message', 'Validation failed')->assertJsonValidationErrorFor($field, 'errors');
})->with([
    'missing company'     => [['company_name' => ''], 'company_name'],
    'bad email'           => [['admin_email' => 'not-an-email'], 'admin_email'],
    'short password'      => [['admin_password' => 'Sh0rt'], 'admin_password'],
    'no digit in password' => [['admin_password' => 'only-letters-here'], 'admin_password'],
    'password too long'   => [['admin_password' => str_repeat('a1', 40)], 'admin_password'],
    'unknown plan'        => [['subscription_plan' => 'platinum'], 'subscription_plan'],
    'bad cycle'           => [['billing_cycle' => 'weekly'], 'billing_cycle'],
    'bad country'         => [['country_code' => 'ZAF'], 'country_code'],
    'subdomain too short' => [['requested_subdomain' => 'ab'], 'requested_subdomain'],
    'no business type'    => [['business_type' => ''], 'business_type'],
]);

it('creates nothing when validation fails', function () {
    onbSignup(['admin_password' => 'weak'])->assertStatus(422);

    expect(DB::table('tenants')->where('subdomain', 'acme')->count())->toBe(0)
        ->and(DB::table('platform_emails')->count())->toBe(0);
});

// ---- atomicity ----

it('rolls EVERYTHING back when admin creation fails after the tenant and plan exist', function () {
    app()->bind(ProvisionsTenantAdmin::class, fn () => new class implements ProvisionsTenantAdmin {
        public function provision(ProvisionTenantAdminDTO $dto): TenantAdminProvisionedDTO
        {
            throw new RuntimeException('boom');
        }
    });
    $this->withoutExceptionHandling();

    expect(fn () => onbSignup())->toThrow(RuntimeException::class, 'boom');

    expect(DB::table('tenants')->where('subdomain', 'acme')->count())->toBe(0)
        ->and(DB::table('tenant_subscriptions')->count())->toBe(0)
        ->and(DB::table('tenant_modules')->count())->toBe(0)
        ->and(DB::table('platform_emails')->count())->toBe(0)
        ->and(DB::table('login_handoffs')->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('rolls everything back when the REAL seat rule rejects the admin (a plan with zero seats)', function () {
    $key = coreEnsurePlan('zero-test', seats: 0);
    $this->withoutExceptionHandling();

    expect(fn () => onbSignup(['subscription_plan' => $key]))->toThrow(SeatLimitReached::class);

    expect(DB::table('tenants')->where('subdomain', 'acme')->count())->toBe(0)
        ->and(DB::table('tenant_subscriptions')->count())->toBe(0)
        ->and(DB::table('platform_emails')->count())->toBe(0);
});

it('gives the signup admin every permission of the modules the plan enables', function () {
    $id = onbSignup()->assertCreated()->json('tenant_id');

    app(TenantContext::class)->set($id);
    $admin = User::where('email', 'ada@example.com')->firstOrFail();

    expect($admin->can('core.customers.manage'))->toBeTrue()
        ->and($admin->can('financial.reports.view'))->toBeTrue()   // needs the Financial provider's catalogue registration
        ->and($admin->can('made.up.permission'))->toBeFalse();     // exact grants, not a wildcard
});

it('works on a plan with a seat limit of 1: the admin is seat 1', function () {
    $key = coreEnsurePlan('solo-test', seats: 1);

    $id = onbSignup(['subscription_plan' => $key])->assertCreated()->json('tenant_id');

    app(TenantContext::class)->set($id);
    expect(User::where('is_active', true)->count())->toBe(1);
});

// ---- routing ----

it('serves signup only on the platform root domain', function () {
    $this->postJson('http://acme.platform.test/api/tenants', onbPayload())->assertNotFound();
    $this->postJson('http://admin.platform.test/api/tenants', onbPayload())->assertNotFound();
});

it('does not fail a successful signup when the welcome email cannot be queued', function () {
    Mail::shouldReceive('to')->andThrow(new RuntimeException('queue down'));

    onbSignup()->assertCreated();   // report()ed, never surfaced

    expect(DB::table('tenants')->where('subdomain', 'acme')->count())->toBe(1);
});
