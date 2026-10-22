<?php

use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Core\Models\User;
use Tests\TestCase;

/* Requires the phpunit.xml env from SETUP-4.md (TENANCY_ROOT_DOMAIN=platform.test). */
uses( TestCase::class, DatabaseTransactions::class);

function lgSignup(string $sub, string $email): array
{
    return test()->postJson('http://platform.test/api/tenants', [
        'company_name' => ucfirst($sub), 'admin_name' => 'Ada', 'admin_email' => $email,
        'admin_password' => 'Str0ng-password!', 'requested_subdomain' => $sub, 'business_type' => 'retail',
        'subscription_plan' => 'starter', 'billing_cycle' => 'monthly', 'country_code' => 'ZA',
    ])->assertCreated()->json();
}

beforeEach(function () {
    if (DB::table('plans')->count() === 0) {
        (new PlanCatalogueSeeder())->run();
    }
    Mail::fake();
    RateLimiter::clear('x');
    $this->a = lgSignup('alpha', 'a@example.com');
    $this->b = lgSignup('beta', 'b@example.com');
});

it('shows the login page on a tenant host and redirects an authenticated user away', function () {
    $this->get('http://alpha.platform.test/login')->assertOk();
    $this->post('http://alpha.platform.test/login', ['email' => 'a@example.com', 'password' => 'Str0ng-password!'])->assertRedirect('/dashboard');
    $this->get('http://alpha.platform.test/login')->assertRedirect('/dashboard');
});

it('signs in with the right credentials, case-insensitively on email, and records last login', function () {
    $this->post('http://alpha.platform.test/login', ['email' => ' A@Example.COM ', 'password' => 'Str0ng-password!'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticated('workspace');
    app(\App\Tenancy\TenantContext::class)->set($this->a['tenant_id']);
    expect(User::where('email', 'a@example.com')->value('last_login_at'))->not->toBeNull();
});

it('gives the same generic error for wrong password, unknown email and a deactivated user', function () {
    app(\App\Tenancy\TenantContext::class)->set($this->a['tenant_id']);
    User::query()->update(['is_active' => false]);

    $r1 = $this->post('http://alpha.platform.test/login', ['email' => 'a@example.com', 'password' => 'Str0ng-password!']);
    $r2 = $this->post('http://alpha.platform.test/login', ['email' => 'a@example.com', 'password' => 'wrong-password-1']);
    $r3 = $this->post('http://alpha.platform.test/login', ['email' => 'nobody@example.com', 'password' => 'whatever-123']);

    foreach ([$r1, $r2, $r3] as $r) {
        $r->assertSessionHasErrors(['email' => 'These credentials do not match our records.']);
    }
    $this->assertGuest('workspace');
});

it('cannot sign in to tenant B with tenant A credentials', function () {
    $this->post('http://beta.platform.test/login', ['email' => 'a@example.com', 'password' => 'Str0ng-password!'])
        ->assertSessionHasErrors('email');

    $this->assertGuest('workspace');
});

it('locks out after five failed attempts, even for the right password', function () {
    for ($i = 0; $i < 5; $i++) {
        $this->post('http://alpha.platform.test/login', ['email' => 'a@example.com', 'password' => 'wrong-password-1']);
    }

    $this->post('http://alpha.platform.test/login', ['email' => 'a@example.com', 'password' => 'Str0ng-password!'])
        ->assertSessionHasErrors('email');
    $this->assertGuest('workspace');
});

it('protects the dashboard and sends guests to /login', function () {
    $this->get('http://alpha.platform.test/dashboard')->assertRedirect('http://alpha.platform.test/login');
});

it('shows the dashboard with the enabled modules to a signed-in admin', function () {
    $this->post('http://alpha.platform.test/login', ['email' => 'a@example.com', 'password' => 'Str0ng-password!']);

    // In production every request starts with an empty TenantContext. In a test the singleton
    // survives between requests. Clear context.
    // app(\App\Tenancy\TenantContext::class)->clear();

    $this->get('http://alpha.platform.test/dashboard')->assertOk()->assertInertia(
        fn ($page) => $page->component('Dashboard')->where('modules', ['Core', 'Financial', 'Support'])
            ->where('auth.user.email', 'a@example.com')->where('tenant.subdomain', 'alpha'),
    );
});

it('signs out and invalidates the session', function () {
    $this->post('http://alpha.platform.test/login', ['email' => 'a@example.com', 'password' => 'Str0ng-password!']);

    $this->post('http://alpha.platform.test/logout')->assertRedirect('/login');
    $this->assertGuest('workspace');
    $this->get('http://alpha.platform.test/dashboard')->assertRedirect('http://alpha.platform.test/login');
});

it('does not share the user or tenant with the public signup page', function () {
    $this->get('http://platform.test/')->assertOk()->assertInertia(
        fn ($page) => $page->component('Platform/Signup')->where('auth.user', null)->where('tenant', null)
            ->has('plans', 3)->where('rootDomain', 'platform.test'),
    );
});

it('serves the signup page only on the root domain', function () {
    $this->get('http://admin.platform.test/')->assertNotFound();
});
