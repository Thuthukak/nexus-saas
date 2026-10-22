<?php

use App\Tenancy\Tenant;
use Database\Seeders\PlanCatalogueSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/*
| Requires phpunit.xml to set TENANCY_ROOT_DOMAIN=platform.test (see SETUP-4.md).
*/
uses(TestCase::class, DatabaseTransactions::class);

function hoSignup(string $sub, string $email): array
{
    return test()->postJson('http://platform.test/api/tenants', [
        'company_name' => ucfirst($sub), 'admin_name' => 'Ada', 'admin_email' => $email,
        'admin_password' => 'Str0ng-password!', 'requested_subdomain' => $sub, 'business_type' => 'retail',
        'subscription_plan' => 'starter', 'billing_cycle' => 'monthly', 'country_code' => 'ZA',
    ])->assertCreated()->json();
}

/** Swap the hostname inside a handoff URL (to replay a token on another tenant's host). */
function hoOnHost(string $url, string $from, string $to): string
{
    return str_replace($from.'.platform.test', $to.'.platform.test', $url);
}

beforeEach(function () {
    if (DB::table('plans')->count() === 0) {
        (new PlanCatalogueSeeder())->run();
    }
    Mail::fake();
    $this->a = hoSignup('alpha', 'a@example.com');
    $this->b = hoSignup('beta', 'b@example.com');
});

it('redeems the handoff on the tenant host: logs the admin in and lands on the dashboard', function () {
    $this->get($this->a['handoff_url'])
        ->assertRedirect('/dashboard')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    $this->assertAuthenticated('workspace');
    expect(auth('workspace')->user()->email)->toBe('a@example.com');
    expect(DB::table('login_handoffs')->where('tenant_id', $this->a['tenant_id'])->value('redeemed_at'))->not->toBeNull();
});

it('regenerates the session ID on login (no session fixation)', function () {
    $fixed = Str::random(40);

    $this->withCookie(config('session.cookie'), $fixed)->get($this->a['handoff_url'])->assertRedirect('/dashboard');

    expect(session()->getId())->not->toBe($fixed);   // if this fails, check the session driver in phpunit.xml
});

it('is single use: the second redemption is refused', function () {
    $this->get($this->a['handoff_url'])->assertRedirect('/dashboard');
    $this->app['auth']->guard('workspace')->logout();

    $this->get($this->a['handoff_url'])->assertForbidden();
});

it('expires after one minute', function () {
    $this->travel(61)->seconds();

    $this->get($this->a['handoff_url'])->assertForbidden();
    $this->assertGuest('workspace');
});

it('still works just inside the one-minute window', function () {
    $this->travel(55)->seconds();

    $this->get($this->a['handoff_url'])->assertRedirect('/dashboard');
});

it('is bound to its tenant: a token for tenant A is refused on tenant B and is not consumed by the attempt', function () {
    $this->get(hoOnHost($this->a['handoff_url'], 'alpha', 'beta'))->assertForbidden();
    $this->assertGuest('workspace');

    $this->get($this->a['handoff_url'])->assertRedirect('/dashboard');   // A's token was not burned
});

it('refuses a tampered signature, user, nonce or expiry', function (string $param, string $value) {
    $parts = parse_url($this->a['handoff_url']);
    parse_str($parts['query'], $query);
    $query[$param] = $value;
    $tampered = $parts['scheme'].'://'.$parts['host'].$parts['path'].'?'.http_build_query($query);

    $this->get($tampered)->assertForbidden();
    $this->assertGuest('workspace');
})->with([
    ['s', str_repeat('0', 64)],
    ['u', '999999'],
    ['n', str_repeat('f', 64)],
    ['e', '4102444800'],
]);

it('refuses missing or malformed parameters', function () {
    $this->get('http://alpha.platform.test/auth/handoff')->assertForbidden();
    $this->get('http://alpha.platform.test/auth/handoff?t=x&u=y&n=z&e=q&s=w')->assertForbidden();
});

it('stores only a hash of the nonce, never the nonce itself', function () {
    parse_str(parse_url($this->a['handoff_url'], PHP_URL_QUERY), $query);

    $stored = DB::table('login_handoffs')->where('tenant_id', $this->a['tenant_id'])->value('nonce_hash');
    expect($stored)->toBe(hash('sha256', $query['n']))->and($stored)->not->toBe($query['n']);
});

it('refuses a deactivated admin even with a valid token', function () {
    app(\App\Tenancy\TenantContext::class)->set($this->a['tenant_id']);
    \Modules\Core\Models\User::query()->update(['is_active' => false]);

    $this->get($this->a['handoff_url'])->assertForbidden();
    $this->assertGuest('workspace');
});

it('refuses a suspended tenant', function () {
    Tenant::query()->whereKey($this->a['tenant_id'])->update(['status' => Tenant::SUSPENDED]);

    $this->get($this->a['handoff_url'])->assertForbidden();
    $this->assertGuest('workspace');
});

it('does not exist on the control plane or the platform root domain', function () {
    parse_str(parse_url($this->a['handoff_url'], PHP_URL_QUERY), $query);
    $qs = http_build_query($query);

    $this->get("http://admin.platform.test/auth/handoff?{$qs}")->assertNotFound();
    $this->get("http://platform.test/auth/handoff?{$qs}")->assertNotFound();
});

it('keeps tenant sessions host-only: the session cookie carries no Domain attribute', function () {
    $response = $this->get($this->a['handoff_url']);

    $cookie = collect($response->headers->getCookies())
        ->first(fn ($c) => $c->getName() === config('session.cookie'));

    expect($cookie)->not->toBeNull()
        ->and($cookie->getDomain())->toBeNull();
});
