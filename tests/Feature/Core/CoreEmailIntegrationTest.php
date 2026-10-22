<?php

use App\Kernel\Contracts\EmailSender;
use App\Kernel\Contracts\ReplyAddressIssuer;
use App\Kernel\Dtos\OutboundEmailDTO;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Modules\Core\Email\EmailGateway;
use Modules\Core\Email\EmailSettings;
use Modules\Core\Email\ReplyTokenSigner;
use Modules\Core\Email\SmtpConnectionFailed;
use Modules\Core\Email\SmtpConnectionTester;
use Modules\Core\Email\SmtpSettings;
use Modules\Core\Email\SmtpTransportFactory;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

require_once __DIR__.'/CoreTestHelpers.php';
require_once __DIR__.'/CoreHttpHelpers.php';

uses( TestCase::class, DatabaseTransactions::class);

const EM_MS_TENANT = '5b2f3c1a-7d4e-4f6a-9b8c-1d2e3f4a5b6c';

/** A fake relay tester: records calls, optionally fails. */
function emFakeTester(?string $failWith = null): object
{
    $t = new class implements SmtpConnectionTester {
        public array $calls = [];
        public ?string $fail = null;

        public function test(SmtpSettings $s): void
        {
            $this->calls[] = $s;
            if ($this->fail !== null) {
                throw new SmtpConnectionFailed($this->fail);
            }
        }
    };
    $t->fail = $failWith;
    app()->instance(SmtpConnectionTester::class, $t);

    return $t;
}

/** A fake relay sender for delivery: records the messages it was asked to send. */
function emFakeRelay(): object
{
    $f = new class(app(\Modules\Core\Email\SmtpHostGuard::class)) extends SmtpTransportFactory {
        public array $sent = [];

        public function send(SmtpSettings $s, Email $email): void
        {
            $this->sent[] = [$s, $email];
        }
    };
    app()->instance(SmtpTransportFactory::class, $f);

    return $f;
}

function emUpgrade(string $tenantId, string $plan = 'enterprise'): void
{
    DB::table('tenant_subscriptions')->where('tenant_id', $tenantId)->update(['plan_id' => DB::table('plans')->where('key', $plan)->value('id')]);
}

/** Fake Microsoft: token endpoint, mailbox lookup, sendMail. */
function emFakeMicrosoft(bool $mailboxExists = true, int $sendStatus = 202): void
{
    Http::fake(function (HttpRequest $r) use ($mailboxExists, $sendStatus) {
        $url = $r->url();
        if (str_contains($url, '/oauth2/v2.0/token')) {
            return Http::response(['access_token' => 'tok-'.uniqid(), 'expires_in' => 3600]);
        }
        if (str_ends_with($url, '/sendMail')) {
            return Http::response('', $sendStatus);
        }

        return $mailboxExists ? Http::response(['id' => 'u1']) : Http::response(['error' => 'nope'], 404);
    });
}

function emPost($t, string $host, $user, array $body)
{
    $r = $t->actingAs($user, 'workspace')->postJson(coreHost($host, '/core/email-integration'), $body);

    coreAsTenant($host === 'alpha' ? $t->idA : $t->idB);

    return $r;
}

function emStateFrom(string $consentUrl): string
{
    parse_str(parse_url($consentUrl, PHP_URL_QUERY), $q);

    return $q['state'];
}

function emCallback($t, array $q)
{
    return $t->get('http://platform.test/integrations/microsoft/callback?'.http_build_query($q));
}

beforeEach(function () {
    config([
        'mail.default' => 'array', 'queue.default' => 'sync', 'mail.from.address' => 'no-reply@platform.test',
        'services.microsoft_graph.client_id' => 'client-123', 'services.microsoft_graph.client_secret' => 'secret-xyz',
    ]);
    [$this->idA, $this->adminA] = coreHttpTenant('alpha');    // professional: NOT enterprise
    [$this->idB, $this->adminB] = coreHttpTenant('beta');
    emUpgrade($this->idB);                                      // enterprise
    app('mail.manager')->mailer('array')->getSymfonyTransport()->flush();   // drop the signup welcome emails
    $this->tester = emFakeTester();
    $this->smtp = ['email_provider' => 'custom_smtp', 'from_address' => 'noreply@example.com', 'smtp_host' => 'smtp.example.com', 'smtp_port' => 587, 'smtp_username' => 'relay', 'smtp_password' => 'S3cret!pass'];
});

// ---- defaults ----

it('defaults every tenant to platform_shared with no setup and no stored row', function () {
    $this->actingAs($this->adminA, 'workspace')->get(coreHost('alpha', '/core/email-integration'))->assertOk()
        ->assertInertia(fn ($p) => $p->component('Core/EmailIntegration')
            ->where('settings.email_provider', 'platform_shared')->where('settings.status', 'active')
            ->where('settings.effective_provider', 'platform_shared')->where('canUseTenantProviders', false));

    coreAsTenant($this->idA);
    expect(EmailSettings::query()->count())->toBe(0);
});

it('shows the Enterprise-capable page to an Enterprise tenant', function () {
    $this->actingAs($this->adminB, 'workspace')->get(coreHost('beta', '/core/email-integration'))
        ->assertInertia(fn ($p) => $p->where('canUseTenantProviders', true)->where('microsoftAvailable', true));
});

it('binds the kernel contracts', function () {
    expect(app(EmailSender::class))->toBeInstanceOf(EmailGateway::class)
        ->and(app(ReplyAddressIssuer::class)->issue($this->idA, 'ticket', '42'))->toStartWith('reply+')->toContain('@reply.');
});

// ---- permission and plan gates ----

it('returns 403 "Missing permission or plan is not Enterprise" for Microsoft 365 and custom SMTP on a non-Enterprise plan, and stores nothing', function (string $provider) {
    emPost($this, 'alpha', $this->adminA, ['email_provider' => $provider, 'from_address' => 'a@example.com'] + $this->smtp)
        ->assertStatus(403)->assertJsonPath('message', 'Missing permission or plan is not Enterprise');

    expect(EmailSettings::query()->count())->toBe(0)->and($this->tester->calls)->toBe([]);
})->with(['microsoft365', 'custom_smtp']);

it('returns 403 for a user without core.settings.manage, even on an Enterprise plan', function () {
    app(TenantContext::class)->set($this->idB);
    $plain = coreHttpPlainUser('beta');

    emPost($this, 'beta', $plain, $this->smtp)->assertStatus(403);
    expect(EmailSettings::query()->count())->toBe(0);
});

it('requires a signed-in user', function () {
    $this->postJson(coreHost('beta', '/core/email-integration'), $this->smtp)->assertUnauthorized();
});

it('lets any plan choose (or return to) platform_shared', function () {
    emPost($this, 'alpha', $this->adminA, ['email_provider' => 'platform_shared'])->assertOk()
        ->assertJsonPath('email_provider', 'platform_shared')->assertJsonPath('status', 'active')->assertJsonPath('from_address', 'no-reply@platform.test');
});

// ---- validation ----

it('returns 422 "Validation failed" for bad input and saves nothing', function (array $override, string $field) {
    $r = emPost($this, 'beta', $this->adminB, array_merge($this->smtp, $override))->assertStatus(422);

    expect($r->json('message'))->toBe('Validation failed')->and($r->json('errors'))->toHaveKey($field)
        ->and(EmailSettings::query()->count())->toBe(0)->and($this->tester->calls)->toBe([]);
})->with([
    'unknown provider' => [['email_provider' => 'carrier_pigeon'], 'email_provider'],
    'missing provider' => [['email_provider' => ''], 'email_provider'],
    'bad from' => [['from_address' => 'not-an-address'], 'from_address'],
    'newline in from' => [['from_address' => "a@example.com\nBcc: x@y.z"], 'from_address'],
    'no host' => [['smtp_host' => ''], 'smtp_host'],
    'forbidden port' => [['smtp_port' => 3306], 'smtp_port'],
    'no username' => [['smtp_username' => ''], 'smtp_username'],
    'no password' => [['smtp_password' => ''], 'smtp_password'],
]);

// ---- custom SMTP ----

it('tests the relay before saving, then stores it active with the credentials encrypted', function () {
    $r = emPost($this, 'beta', $this->adminB, $this->smtp)->assertOk();

    expect($r->json())->toMatchArray(['email_provider' => 'custom_smtp', 'from_address' => 'noreply@example.com', 'status' => 'active'])
        ->and($this->tester->calls)->toHaveCount(1)->and($this->tester->calls[0]->host)->toBe('smtp.example.com');

    expect($r->getContent())->not->toContain('S3cret!pass')->not->toContain('credentials');

    $raw = DB::table('tenant_email_settings')->where('tenant_id', $this->idB)->value('credentials');
    expect($raw)->not->toContain('S3cret!pass')->not->toContain('relay')                      // ciphertext only
        ->and(EmailSettings::query()->first()->credentials['password'])->toBe('S3cret!pass'); // decrypts for the owning app
});

it('does NOT save or activate a relay that fails its connection test, and keeps the previous configuration', function () {
    emPost($this, 'beta', $this->adminB, array_merge($this->smtp, ['from_address' => 'old@example.com']))->assertOk();

    $this->tester->fail = 'Could not connect and sign in to the SMTP server: bad credentials';
    $r = emPost($this, 'beta', $this->adminB, array_merge($this->smtp, ['from_address' => 'new@example.com', 'smtp_password' => 'wrong']))->assertStatus(422);

    expect($r->json('errors.smtp_host.0'))->toContain('bad credentials')
        ->and(EmailSettings::query()->first()->from_address)->toBe('old@example.com')
        ->and(EmailSettings::query()->first()->status)->toBe('active');
});

it('never activates a first-time relay whose test failed', function () {
    $this->tester->fail = 'refused';
    emPost($this, 'beta', $this->adminB, $this->smtp)->assertStatus(422);

    expect(EmailSettings::query()->count())->toBe(0);
});

it('returning to platform_shared removes the stored credentials', function () {
    emPost($this, 'beta', $this->adminB, $this->smtp)->assertOk();
    emPost($this, 'beta', $this->adminB, ['email_provider' => 'platform_shared'])->assertOk();

    expect(EmailSettings::query()->count())->toBe(0);
});

// ---- Microsoft 365 consent ----

it('starts Microsoft consent: pending, a consent URL for application-level permissions, and mail keeps using the platform meanwhile', function () {
    $r = emPost($this, 'beta', $this->adminB, ['email_provider' => 'microsoft365', 'from_address' => 'noreply@example.com'])->assertOk();

    $url = $r->json('consent_url');
    parse_str(parse_url($url, PHP_URL_QUERY), $q);
    expect($r->json('status'))->toBe('pending')->and($r->json('effective_provider'))->toBe('platform_shared')
        ->and($url)->toStartWith('https://login.microsoftonline.com/')
        ->and($q['client_id'])->toBe('client-123')->and($q['scope'])->toBe('https://graph.microsoft.com/.default')
        ->and($q['redirect_uri'])->toContain('platform.test/integrations/microsoft/callback')->and($q['redirect_uri'])->not->toContain('beta.')
        ->and($q['state'])->not->toBeEmpty()
        ->and($url)->not->toContain('secret-xyz');
});

it('reports Microsoft 365 as not available when the platform has no Azure app configured', function () {
    config(['services.microsoft_graph.client_id' => null]);

    emPost($this, 'beta', $this->adminB, ['email_provider' => 'microsoft365', 'from_address' => 'noreply@example.com'])->assertStatus(422)->assertJsonPath('message', 'Validation failed');
});

it('completes consent: verifies the mailbox in the consenting tenant, stores the token encrypted, activates, and sends the admin back to their workspace', function () {
    emFakeMicrosoft();
    $state = emStateFrom(emPost($this, 'beta', $this->adminB, ['email_provider' => 'microsoft365', 'from_address' => 'noreply@example.com'])->json('consent_url'));

    $r = emCallback($this, ['admin_consent' => 'True', 'tenant' => EM_MS_TENANT, 'state' => $state]);

    expect($r->headers->get('Location'))->toContain('beta.platform.test/core/email-integration?consent=ok');
    $r->assertHeader('Referrer-Policy', 'no-referrer');

    app(TenantContext::class)->set($this->idB);
    $row = EmailSettings::query()->first();
    expect($row->status)->toBe('active')->and($row->credentials['ms_tenant_id'])->toBe(EM_MS_TENANT)
        ->and(DB::table('tenant_email_settings')->where('tenant_id', $this->idB)->value('credentials'))->not->toContain('tok-')   // encrypted at rest
        ->and($row->consent_nonce_hash)->toBeNull();
});

it('refuses a replayed consent link (single use)', function () {
    emFakeMicrosoft();
    $state = emStateFrom(emPost($this, 'beta', $this->adminB, ['email_provider' => 'microsoft365', 'from_address' => 'noreply@example.com'])->json('consent_url'));
    $q = ['admin_consent' => 'True', 'tenant' => EM_MS_TENANT, 'state' => $state];

    emCallback($this, $q)->assertRedirect();
    emCallback($this, $q)->assertStatus(400);
});

it('refuses a forged, tampered, garbage or expired state', function () {
    emFakeMicrosoft();
    $state = emStateFrom(emPost($this, 'beta', $this->adminB, ['email_provider' => 'microsoft365', 'from_address' => 'noreply@example.com'])->json('consent_url'));
    $q = ['admin_consent' => 'True', 'tenant' => EM_MS_TENANT];

    emCallback($this, $q + ['state' => 'garbage'])->assertStatus(400);
    emCallback($this, $q + ['state' => ''])->assertStatus(400);
    emCallback($this, $q + ['state' => substr($state, 0, -2).'xx'])->assertStatus(400);

    $this->travel(16)->minutes();
    emCallback($this, $q + ['state' => $state])->assertStatus(400);
    app(TenantContext::class)->set($this->idB);
    expect(EmailSettings::query()->first()->status)->toBe('pending');
});

it('invalidates an earlier consent link when the admin starts again', function () {
    emFakeMicrosoft();
    $first = emStateFrom(emPost($this, 'beta', $this->adminB, ['email_provider' => 'microsoft365', 'from_address' => 'noreply@example.com'])->json('consent_url'));
    emPost($this, 'beta', $this->adminB, ['email_provider' => 'microsoft365', 'from_address' => 'noreply@example.com'])->assertOk();

    emCallback($this, ['admin_consent' => 'True', 'tenant' => EM_MS_TENANT, 'state' => $first])->assertStatus(400);
});

it('records a declined consent as denied and does not activate', function () {
    $state = emStateFrom(emPost($this, 'beta', $this->adminB, ['email_provider' => 'microsoft365', 'from_address' => 'noreply@example.com'])->json('consent_url'));

    $r = emCallback($this, ['error' => 'access_denied', 'state' => $state]);

    expect($r->headers->get('Location'))->toContain('consent=denied');
    app(TenantContext::class)->set($this->idB);
    expect(EmailSettings::query()->first()->status)->toBe('failed')->and(EmailSettings::query()->first()->credentials)->toBeNull();
});

it('refuses to activate when the sender is not a mailbox in the consenting Microsoft tenant', function () {
    emFakeMicrosoft(mailboxExists: false);
    $state = emStateFrom(emPost($this, 'beta', $this->adminB, ['email_provider' => 'microsoft365', 'from_address' => 'ceo@victim.example'])->json('consent_url'));

    $r = emCallback($this, ['admin_consent' => 'True', 'tenant' => EM_MS_TENANT, 'state' => $state]);

    expect($r->headers->get('Location'))->toContain('consent=failed');
    app(TenantContext::class)->set($this->idB);
    expect(EmailSettings::query()->first()->status)->toBe('failed')->and(EmailSettings::query()->first()->last_error)->toContain('not a mailbox');
});

it('rejects a malformed Microsoft tenant id', function () {
    emFakeMicrosoft();
    $state = emStateFrom(emPost($this, 'beta', $this->adminB, ['email_provider' => 'microsoft365', 'from_address' => 'noreply@example.com'])->json('consent_url'));

    expect(emCallback($this, ['admin_consent' => 'True', 'tenant' => '../../evil', 'state' => $state])->headers->get('Location'))->toContain('consent=denied');
    Http::assertNothingSent();
});

it('serves the consent callback only on the platform root domain', function () {
    $this->get('http://beta.platform.test/integrations/microsoft/callback?state=x')->assertNotFound();
    $this->get('http://admin.platform.test/integrations/microsoft/callback?state=x')->assertNotFound();
});

// ---- delivery ----

function emSend(array $o = []): void
{
    app(EmailSender::class)->send(new OutboundEmailDTO(...array_merge(['tenantId' => test()->idA, 'toAddress' => 'cust@example.org', 'subject' => 'Hello', 'textBody' => 'Body text'], $o)));
}

function emSent(): array
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->map(fn ($m) => $m->getOriginalMessage())->all();
}

it('delivers through the platform domain by default, naming the user and routing replies to them', function () {
    emSend(['senderUserId' => $this->adminA->id]);

    $m = emSent()[0];
    expect($m->getFrom()[0]->getAddress())->toBe('no-reply@platform.test')->and($m->getFrom()[0]->getName())->toContain('via')->toContain($this->adminA->name)
        ->and($m->getReplyTo()[0]->getAddress())->toBe($this->adminA->email)->and($m->getTo()[0]->getAddress())->toBe('cust@example.org');
});

it('adds a genuine signed Reply-To for a threaded platform_shared email, bound to THIS tenant and record', function () {
    emSend(['senderUserId' => $this->adminA->id, 'replyRecordType' => 'ticket', 'replyRecordId' => '4821']);

    $reply = emSent()[0]->getReplyTo()[0]->getAddress();
    [$local, $domain] = explode('@', $reply);
    expect($domain)->toBe(config('tenancy.reply_domain'))
        ->and(app(ReplyTokenSigner::class)->verify($local))->toBe(['tenant_id' => $this->idA, 'record_type' => 'ticket', 'record_id' => '4821']);
});

it('does not queue anything for an invalid message', function () {
    Queue::fake();

    expect(fn () => emSend(['subject' => "Hi\nBcc: x@y.z"]))->toThrow(InvalidArgumentException::class);
    Queue::assertNothingPushed();
});

it('sends through the tenant\'s Microsoft 365 as the user\'s own address, refreshing the token when it has expired', function () {
    emFakeMicrosoft();
    $state = emStateFrom(emPost($this, 'beta', $this->adminB, ['email_provider' => 'microsoft365', 'from_address' => 'noreply@example.com'])->json('consent_url'));
    emCallback($this, ['admin_consent' => 'True', 'tenant' => EM_MS_TENANT, 'state' => $state]);

    app(TenantContext::class)->set($this->idB);
    $row = EmailSettings::query()->first();
    $row->credentials = ['ms_tenant_id' => EM_MS_TENANT, 'access_token' => 'stale', 'expires_at' => time() - 10];
    $row->save();
    
    Http::swap(new \Illuminate\Http\Client\Factory()); 
    Http::fake(fn (HttpRequest $r) => str_contains($r->url(), 'oauth2') ? Http::response(['access_token' => 'fresh-token', 'expires_in' => 3600]) : Http::response('', 202));

    emSend(['tenantId' => $this->idB, 'senderUserId' => $this->adminB->id]);

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/users/'.rawurlencode('beta@example.com').'/sendMail')   // user's own mailbox (same domain)
        && $r->hasHeader('Authorization', 'Bearer fresh-token')
        && $r['message']['toRecipients'][0]['emailAddress']['address'] === 'cust@example.org'
        && $r['message']['subject'] === 'Hello');
    expect(emSent())->toBe([]);   // nothing went through the platform mailer
});

it('sends through the tenant\'s own relay when custom_smtp is active', function () {
    emPost($this, 'beta', $this->adminB, $this->smtp)->assertOk();
    $relay = emFakeRelay();

    emSend(['tenantId' => $this->idB, 'senderUserId' => $this->adminB->id]);

    expect($relay->sent)->toHaveCount(1)->and($relay->sent[0][0]->username)->toBe('relay')
        ->and($relay->sent[0][1]->getFrom()[0]->getAddress())->toBe('beta@example.com')   // the user's own address
        ->and(emSent())->toBe([]);
});

it('falls back to platform_shared whenever the tenant\'s provider is not active (pending or failed)', function (string $status) {
    emPost($this, 'beta', $this->adminB, $this->smtp)->assertOk();
    app(TenantContext::class)->set($this->idB);
    EmailSettings::query()->update(['status' => $status]);
    $relay = emFakeRelay();

    emSend(['tenantId' => $this->idB, 'senderUserId' => $this->adminB->id]);

    expect($relay->sent)->toBe([])->and(emSent())->toHaveCount(1);
    expect(emSent()[0]->getFrom()[0]->getAddress())->toBe('no-reply@platform.test');
})->with(['pending', 'failed']);

it('stops using a tenant-owned provider (back to platform_shared) once the plan no longer includes it', function () {
    emPost($this, 'beta', $this->adminB, $this->smtp)->assertOk();
    emUpgrade($this->idB, 'starter');
    $relay = emFakeRelay();

    emSend(['tenantId' => $this->idB]);

    expect($relay->sent)->toBe([])->and(emSent())->toHaveCount(1);
    $this->actingAs($this->adminB, 'workspace')->get(coreHost('beta', '/core/email-integration'))->assertInertia(fn ($p) => $p->where('settings.effective_provider', 'platform_shared'));
});

it('records a tenant-provider delivery failure for the admin to see, without re-routing the message through the platform', function () {
    emPost($this, 'beta', $this->adminB, $this->smtp)->assertOk();
    $f = new class(app(\Modules\Core\Email\SmtpHostGuard::class)) extends SmtpTransportFactory {
        public function send(SmtpSettings $s, Email $email): void { throw new RuntimeException('relay said no'); }
    };
    app()->instance(SmtpTransportFactory::class, $f);

    try {
        emSend(['tenantId' => $this->idB]);
    } catch (Throwable) {
    }

    app(TenantContext::class)->set($this->idB);
    expect(EmailSettings::query()->first()->last_error)->toContain('relay said no')->and(emSent())->toBe([]);
});

// ---- isolation and constraints ----

it('keeps each tenant\'s email configuration private', function () {
    emPost($this, 'beta', $this->adminB, $this->smtp)->assertOk();

    app(TenantContext::class)->set($this->idA);
    expect(DB::table('tenant_email_settings')->count())->toBe(0);
    $this->actingAs($this->adminA, 'workspace')->get(coreHost('alpha', '/core/email-integration'))->assertInertia(fn ($p) => $p->where('settings.email_provider', 'platform_shared'));
});

it('enforces the shape of a stored configuration in the database', function () {
    app(TenantContext::class)->set($this->idB);
    $row = ['tenant_id' => $this->idB, 'created_at' => now(), 'updated_at' => now()];

    expect(fn () => coreInSavepoint(fn () => DB::table('tenant_email_settings')->insert($row + ['provider' => 'carrier_pigeon'])))->toThrow(QueryException::class);
    expect(fn () => coreInSavepoint(fn () => DB::table('tenant_email_settings')->insert($row + ['provider' => 'custom_smtp'])))->toThrow(QueryException::class);   // no sender
    expect(fn () => coreInSavepoint(fn () => DB::table('tenant_email_settings')->insert($row + ['provider' => 'custom_smtp', 'from_address' => 'a@b.test', 'status' => 'active'])))->toThrow(QueryException::class); // active, no credentials
    expect(DB::selectOne("select relrowsecurity as r from pg_class where relname = 'tenant_email_settings'")->r)->toBeTrue();
});
