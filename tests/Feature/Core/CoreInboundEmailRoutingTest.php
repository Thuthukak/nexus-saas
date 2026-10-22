<?php

use App\Kernel\Contracts\InboundRecordTypeRegistrar;
use App\Kernel\Dtos\InboundEmailDTO;
use App\Kernel\Dtos\InboundEmailReceived;
use App\Platform\Modules\ModuleManager;
use App\Tenancy\Tenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Email\ReplyTokenSigner;
use Modules\Core\Inbound\ImapMailbox;
use Modules\Core\Inbound\InboundEmailRouter;
use Modules\Core\Inbound\RawImapMessage;
use Modules\Core\Inbound\RouteOutcome;
use Tests\TestCase;

require_once __DIR__.'/CoreTestHelpers.php';
require_once __DIR__.'/CoreHttpHelpers.php';

uses(TestCase::class, DatabaseTransactions::class);

/** Router tests are written against InboundEmailDTO directly: they stay valid whichever entry point delivers the mail. */
function inDto(array $o = []): InboundEmailDTO
{
    return new InboundEmailDTO(...array_merge([
        'messageId' => 'msg-'.uniqid(), 'recipient' => test()->addrA, 'fromAddress' => 'tess@customer.test', 'subject' => 'Re: Printer',
        'textBody' => "Fixed, thanks.\n\n> old", 'replyText' => 'Fixed, thanks.', 'receivedAt' => '2026-10-19T10:00:00+00:00', 'source' => 'test',
    ], $o));
}

function inAddress(string $tenantId, string $type = 'ticket', string $id = '4821'): string
{
    return app(ReplyTokenSigner::class)->issue($tenantId, $type, $id).'@'.config('tenancy.reply_domain');
}

function inLog(): \Illuminate\Support\Collection
{
    return DB::table('inbound_email_log')->orderBy('id')->get();
}

beforeEach(function () {
    Mail::fake();
    config(['tenancy.reply_domain' => 'reply.pendagraphics.co.za', 'mail.from.address' => 'no-reply@platform.test']);
    [$this->idA, $this->adminA] = coreHttpTenant('alpha');
    [$this->idB, $this->adminB] = coreHttpTenant('beta');
    app(InboundRecordTypeRegistrar::class)->register('Support', 'ticket');
    $this->router = app(InboundEmailRouter::class);
    $this->addrA = inAddress($this->idA);
    $this->received = [];
    Event::listen(InboundEmailReceived::class, function (InboundEmailReceived $e) {
        $this->received[] = ['dto' => $e->dto, 'context' => app(TenantContext::class)->id()];
    });
});

// ---- the happy path ----

it('routes a validly signed reply: tenant and record come from the signature, the tenant context is set, and one event is dispatched', function () {
    $out = $this->router->route($m = inDto());

    expect($out->status)->toBe(RouteOutcome::DELIVERED)->and($this->received)->toHaveCount(1);
    $r = $this->received[0];
    expect($r['dto']->tenantId)->toBe($this->idA)->and($r['dto']->recordType)->toBe('ticket')->and($r['dto']->recordId)->toBe('4821')
        ->and($r['dto']->email)->toBe($m)->and($r['context'])->toBe($this->idA);

    $row = inLog()->first();
    expect($row->status)->toBe('delivered')->and($row->tenant_id)->toBe($this->idA)->and($row->record_type)->toBe('ticket')->and($row->record_id)->toBe('4821');
});

it('restores whatever tenant context was active before the message was routed', function () {
    app(TenantContext::class)->set($this->idB);

    $this->router->route(inDto());

    expect(app(TenantContext::class)->id())->toBe($this->idB);
});

it('works with no tenant context at all (the poller runs outside any tenant)', function () {
    app(TenantContext::class)->clear();

    expect($this->router->route(inDto())->status)->toBe(RouteOutcome::DELIVERED)->and(app(TenantContext::class)->id())->toBeNull();
});

it('accepts the address in any letter case', function () {
    expect($this->router->route(inDto(['recipient' => strtoupper($this->addrA)]))->status)->toBe(RouteOutcome::DELIVERED);
});

// ---- signature: everything is refused unless genuine ----

it('rejects a missing, forged or tampered signature, and never delivers anything', function (string $local) {
    $out = $this->router->route(inDto(['recipient' => $local.'@'.config('tenancy.reply_domain')]));

    expect($out->status)->toBe(RouteOutcome::REJECTED)->and($out->reason)->toBe('bad_signature')->and($this->received)->toBe([]);
})->with(function () {
    return [
        'no token' => ['reply'],
        'plus but nothing' => ['reply+'],
        'plain support address' => ['support'],
        'random token' => ['reply+'.str_repeat('a', 26).'.ticket-4821_'.str_repeat('b', 16)],
        'garbage' => ['reply+not-a-token'],
    ];
});

it('rejects a token whose record or signature was changed after signing', function () {
    $good = explode('@', $this->addrA)[0];
    $dom = '@'.config('tenancy.reply_domain');

    foreach ([str_replace('ticket-4821', 'ticket-4822', $good), substr($good, 0, -1).(str_ends_with($good, 'a') ? 'b' : 'a'), $good.'x'] as $bad) {
        expect($this->router->route(inDto(['recipient' => $bad.$dom]))->reason)->toBe('bad_signature');
    }
    expect($this->received)->toBe([]);
});

it('never uses In-Reply-To or References: a reply to a real thread with a forged address is still refused', function () {
    // The attacker knows nothing but a thread's Message-ID. The DTO cannot even carry those headers.
    $out = $this->router->route(inDto(['recipient' => 'support@'.config('tenancy.reply_domain'), 'subject' => 'Re: Printer']));

    expect($out->reason)->toBe('bad_signature')->and($this->received)->toBe([]);
});

it('cannot be steered into another tenant: swapping the tenant part of a valid address invalidates it', function () {
    [$prefixA, $restA] = explode('.', explode('@', $this->addrA)[0], 2);
    [, $restB] = explode('.', explode('@', inAddress($this->idB))[0], 2);

    $out = $this->router->route(inDto(['recipient' => $prefixA.'.'.$restB.'@'.config('tenancy.reply_domain')]));

    expect($out->reason)->toBe('bad_signature')->and($this->received)->toBe([]);
});

it('routes tenant B\'s genuine address to tenant B only', function () {
    $this->router->route(inDto(['recipient' => inAddress($this->idB, 'ticket', '7')]));

    expect($this->received)->toHaveCount(1)->and($this->received[0]['dto']->tenantId)->toBe($this->idB)->and($this->received[0]['context'])->toBe($this->idB);
});

it('rejects mail for another domain even with a genuine token', function () {
    $out = $this->router->route(inDto(['recipient' => explode('@', $this->addrA)[0].'@evil.test']));

    expect($out->reason)->toBe('wrong_domain')->and($this->received)->toBe([]);
});

it('rejects a validly signed token for a tenant that does not exist', function () {
    $out = $this->router->route(inDto(['recipient' => inAddress('9c1d2e3f-4a5b-4c6d-8e7f-0a1b2c3d4e5f')]));

    expect($out->reason)->toBe('unknown_tenant')->and($this->received)->toBe([])->and(inLog()->first()->tenant_id)->toBeNull();
});

it('rejects mail for a suspended tenant', function () {
    Tenant::query()->whereKey($this->idA)->update(['status' => Tenant::SUSPENDED]);

    expect($this->router->route(inDto())->reason)->toBe('tenant_inactive')->and($this->received)->toBe([]);
});

// ---- record types and modules ----

it('rejects a record type no module has claimed', function () {
    expect($this->router->route(inDto(['recipient' => inAddress($this->idA, 'quote', '1')]))->reason)->toBe('unknown_record_type')->and($this->received)->toBe([]);
});

it('stops routing to a module that is disabled for the tenant, and resumes when it is re-enabled; other tenants are unaffected', function () {
    app(ModuleManager::class)->disable($this->idA, 'Support');

    expect($this->router->route(inDto())->reason)->toBe('module_disabled')
        ->and($this->router->route(inDto(['recipient' => inAddress($this->idB)]))->status)->toBe(RouteOutcome::DELIVERED);

    app(ModuleManager::class)->enable($this->idA, 'Support');

    expect($this->router->route(inDto())->status)->toBe(RouteOutcome::DELIVERED);
});

// ---- at most once ----

it('processes a message at most once, however often it is delivered', function () {
    $m = inDto(['messageId' => 'same-id@customer.test']);

    expect($this->router->route($m)->status)->toBe(RouteOutcome::DELIVERED)
        ->and($this->router->route($m)->status)->toBe(RouteOutcome::DUPLICATE)
        ->and($this->router->route(inDto(['messageId' => '<SAME-ID@customer.test>']))->status)->toBe(RouteOutcome::DUPLICATE)   // brackets and case do not matter
        ->and($this->received)->toHaveCount(1)->and(inLog())->toHaveCount(1);
});

it('treats the same Message-ID sent to a different thread as a different message', function () {
    $this->router->route(inDto(['messageId' => 'm1']));

    expect($this->router->route(inDto(['messageId' => 'm1', 'recipient' => inAddress($this->idA, 'ticket', '9')]))->status)->toBe(RouteOutcome::DELIVERED);
});

it('cannot be poisoned: a forged message reusing a real Message-ID does not block the genuine reply', function () {
    $this->router->route(inDto(['messageId' => 'real-id', 'recipient' => 'reply+junk@'.config('tenancy.reply_domain')]));   // attacker, first

    expect($this->router->route(inDto(['messageId' => 'real-id']))->status)->toBe(RouteOutcome::DELIVERED);
});

it('remembers refusals too, so a re-polled forged message is not reprocessed', function () {
    $m = inDto(['recipient' => 'reply+junk@'.config('tenancy.reply_domain'), 'messageId' => 'forged-1']);

    $this->router->route($m);
    $this->router->route($m);

    expect(inLog())->toHaveCount(1)->and(inLog()->first()->reason)->toBe('bad_signature');
});

it('rolls the claim back when the owning module fails, so the message can be retried', function () {
    $fail = true;
    Event::listen(InboundEmailReceived::class, function () use (&$fail) {
        if ($fail) {
            throw new RuntimeException('listener boom');
        }
    });
    $m = inDto(['messageId' => 'retry-me']);

    expect(fn () => $this->router->route($m))->toThrow(RuntimeException::class, 'listener boom');
    expect(inLog())->toHaveCount(0);

    $fail = false;
    expect($this->router->route($m)->status)->toBe(RouteOutcome::DELIVERED)->and($this->router->route($m)->status)->toBe(RouteOutcome::DUPLICATE);
});

// ---- loop and abuse protection ----

it('refuses auto-generated mail, our own mail, empty message ids and oversize bodies', function (array $override, string $reason) {
    $out = $this->router->route(inDto($override));

    expect($out->reason)->toBe($reason)->and($this->received)->toBe([]);
})->with([
    'auto reply' => [['autoGenerated' => true], 'auto_generated'],
    'from the platform address' => [['fromAddress' => 'no-reply@platform.test'], 'own_address'],
    'from the reply domain' => [['fromAddress' => 'bot@reply.pendagraphics.co.za'], 'own_address'],
    'empty message id' => [['messageId' => '  <>  '], 'invalid_message_id'],
    'text too large' => [['textBody' => str_repeat('a', 200_001)], 'too_large'],
    'html too large' => [['htmlBody' => str_repeat('a', 500_001)], 'too_large'],
]);

// ---- log ----

it('stores no addresses, subjects or content in the routing log', function () {
    $this->router->route(inDto());

    $columns = collect(DB::select("select column_name from information_schema.columns where table_name = 'inbound_email_log'"))->pluck('column_name')->all();
    expect($columns)->toBe(['id', 'dedupe_key', 'tenant_id', 'record_type', 'record_id', 'status', 'reason', 'source', 'created_at']);
});

it('enforces the shape of log rows in the database', function () {
    $row = ['created_at' => now(), 'source' => 'x'];

    expect(fn () => coreInSavepoint(fn () => DB::table('inbound_email_log')->insert($row + ['dedupe_key' => str_repeat('a', 64), 'status' => 'delivered', 'reason' => null])))->toThrow(QueryException::class);   // delivered needs tenant+record
    expect(fn () => coreInSavepoint(fn () => DB::table('inbound_email_log')->insert($row + ['dedupe_key' => str_repeat('b', 64), 'status' => 'rejected'])))->toThrow(QueryException::class);                       // rejected needs a reason
    expect(fn () => coreInSavepoint(fn () => DB::table('inbound_email_log')->insert($row + ['dedupe_key' => str_repeat('c', 64), 'status' => 'maybe', 'reason' => 'x'])))->toThrow(QueryException::class);
    DB::table('inbound_email_log')->insert($row + ['dedupe_key' => str_repeat('d', 64), 'status' => 'rejected', 'reason' => 'x']);
    expect(fn () => coreInSavepoint(fn () => DB::table('inbound_email_log')->insert($row + ['dedupe_key' => str_repeat('d', 64), 'status' => 'rejected', 'reason' => 'x'])))->toThrow(QueryException::class);   // unique
});

it('prunes old log rows', function () {
    DB::table('inbound_email_log')->insert([
        ['dedupe_key' => str_repeat('1', 64), 'status' => 'rejected', 'reason' => 'x', 'source' => 'x', 'created_at' => now()->subDays(90)],
        ['dedupe_key' => str_repeat('2', 64), 'status' => 'rejected', 'reason' => 'x', 'source' => 'x', 'created_at' => now()->subDays(2)],
    ]);

    $this->artisan('email:prune-inbound-log', ['--days' => 30])->assertSuccessful();

    expect(inLog()->pluck('dedupe_key')->all())->toBe([str_repeat('2', 64)]);
});

// ---- the poller adapter ----

function inFakeMailbox(array $messages): object
{
    $mb = new class($messages) implements ImapMailbox {
        public array $moves = [];
        public bool $disconnected = false;

        public function __construct(public array $messages) {}

        public function fetchUnseen(int $limit): array { return array_slice($this->messages, 0, $limit); }

        public function move(RawImapMessage $message, string $folder): void { $this->moves[$message->uid] = $folder; }

        public function disconnect(): void { $this->disconnected = true; }
    };
    app()->instance(ImapMailbox::class, $mb);

    return $mb;
}

function inMessage(string $uid, string $recipient, string $id, string $text = "Thanks!\n", int $size = 400): RawImapMessage
{
    return new RawImapMessage($uid, "Delivered-To: {$recipient}\r\nFrom: Tess <tess@customer.test>\r\nMessage-ID: <{$id}>\r\nSubject: Re: Printer\r\nDate: Mon, 19 Oct 2026 10:00:00 +0000\r\n", $text, null, $size);
}

it('polls: routes good mail, files everything into Processed or Rejected, and disconnects', function () {
    $mb = inFakeMailbox([
        inMessage('1', $this->addrA, 'good-1'),
        inMessage('2', $this->addrA, 'good-1'),                                                           // duplicate delivery of the same mail
        inMessage('3', 'reply+forged@'.config('tenancy.reply_domain'), 'forged-1'),                       // bad signature
        new RawImapMessage('4', "From: x@y.test\r\nSubject: no id or recipient\r\n", 'hi', null, 100),   // unparseable
        inMessage('5', $this->addrA, 'huge-1', 'x', 5_000_000),                                          // oversize
    ]);

    $this->artisan('email:poll-inbound')->assertSuccessful();

    expect($mb->moves)->toBe(['1' => 'Processed', '2' => 'Processed', '3' => 'Rejected', '4' => 'Rejected', '5' => 'Rejected'])
        ->and($mb->disconnected)->toBeTrue()->and($this->received)->toHaveCount(1);
});

it('builds the same DTO outcome as a hand-built one: the router cannot tell which entry point delivered the message', function () {
    inFakeMailbox([inMessage('1', $this->addrA, 'via-poller')]);
    $this->artisan('email:poll-inbound')->assertSuccessful();

    $viaPoller = $this->received[0]['dto'];
    $this->router->route(inDto(['messageId' => 'hand-built', 'source' => 'webhook']));
    $viaOther = $this->received[1]['dto'];

    expect([$viaPoller->tenantId, $viaPoller->recordType, $viaPoller->recordId])->toBe([$viaOther->tenantId, $viaOther->recordType, $viaOther->recordId])
        ->and($viaPoller->email->source)->toBe('imap')->and($viaOther->email->source)->toBe('webhook');
});

it('leaves a message in the inbox for retry when handling fails, and parks it after too many attempts', function () {
    Event::listen(InboundEmailReceived::class, fn () => throw new RuntimeException('module down'));
    $mb = inFakeMailbox([inMessage('1', $this->addrA, 'stubborn')]);

    foreach (range(1, 4) as $_) {
        $this->artisan('email:poll-inbound')->assertFailed();
        expect($mb->moves)->toBe([]);   // still in the inbox
    }
    $this->artisan('email:poll-inbound')->assertFailed();

    expect($mb->moves)->toBe(['1' => 'Rejected'])->and(inLog())->toHaveCount(0);
});
