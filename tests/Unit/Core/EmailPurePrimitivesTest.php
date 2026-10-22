<?php

use App\Kernel\Dtos\OutboundEmailDTO;
use Modules\Core\Email\Base32;
use Modules\Core\Email\EmailComposer;
use Modules\Core\Email\IpGuard;
use Modules\Core\Email\OutboundEmailValidator;
use Modules\Core\Email\ReplyTokenSigner;
use Modules\Core\Email\SenderIdentity;
use Modules\Core\Email\SignedPayload;
use Modules\Core\Email\SmtpHostGuard;
use Modules\Core\Email\SmtpSettings;

const EM_TENANT = '0b9f6c34-5a1e-4d52-9a57-3f6a1c2d8e90';
const EM_OTHER = '9c1d2e3f-4a5b-4c6d-8e7f-0a1b2c3d4e5f';

// ---- base32 ----

it('round-trips base32 and is strict about alphabet and trailing bits', function () {
    foreach ([random_bytes(10), random_bytes(16), "\x00\xff\x10"] as $bytes) {
        expect(Base32::decode(Base32::encode($bytes)))->toBe($bytes);
    }
    expect(Base32::decode('abc1'))->toBeNull()          // '1' is outside the alphabet
        ->and(Base32::decode('ABC'))->toBeNull()         // upper case is not canonical
        ->and(Base32::encode(str_repeat("\xff", 16)))->toMatch('/^[a-z2-7]{26}$/');
});

// ---- signed payload ----

it('signs, verifies and expires state; a token for one purpose is useless for another', function () {
    $s = new SignedPayload('k1', 'm365-consent');
    $tok = $s->encode(['t' => EM_TENANT, 'n' => 'abc'], 2_000_000_000);

    expect($s->decode($tok, 1_900_000_000)['t'])->toBe(EM_TENANT)
        ->and($s->decode($tok, 2_000_000_001))->toBeNull()                          // expired
        ->and((new SignedPayload('k2', 'm365-consent'))->decode($tok, 1_900_000_000))->toBeNull()   // other key
        ->and((new SignedPayload('k1', 'other-flow'))->decode($tok, 1_900_000_000))->toBeNull()      // other purpose
        ->and($s->decode('garbage', 1))->toBeNull()
        ->and($s->decode('a.b.c', 1))->toBeNull();

    [$body, $sig] = explode('.', $tok);
    $forged = rtrim(strtr(base64_encode(json_encode(['t' => EM_OTHER, 'n' => 'abc', 'exp' => 2_000_000_000])), '+/', '-_'), '=');
    expect($s->decode($forged.'.'.$sig, 1_900_000_000))->toBeNull();                // body swapped, signature reused
});

// ---- reply token ----

it('issues a signed local part that verifies back to the tenant and record', function () {
    $signer = new ReplyTokenSigner('key-one');
    $local = $signer->issue(EM_TENANT, 'ticket', 'abc123');

    expect($local)->toMatch('/^reply\+[a-z2-7]{26}\.ticket-abc123_[a-z2-7]{16}$/')
        ->and(strlen($local))->toBeLessThanOrEqual(64)
        ->and($signer->verify($local))->toBe(['tenant_id' => EM_TENANT, 'record_type' => 'ticket', 'record_id' => 'abc123']);
});

it('keeps the worst-case address inside the 64 character local-part limit', function () {
    $local = (new ReplyTokenSigner('k'))->issue(EM_TENANT, 'ticket', 'abcdefg');

    expect(strlen($local))->toBeLessThanOrEqual(64);
});

it('still verifies when a mail system changes the case', function () {
    $signer = new ReplyTokenSigner('key-one');

    expect($signer->verify(strtoupper($signer->issue(EM_TENANT, 'ticket', '42'))))->not->toBeNull();
});

it('rejects any tampering: record, tenant, signature, key, truncation, extra text', function () {
    $signer = new ReplyTokenSigner('key-one');
    $local = $signer->issue(EM_TENANT, 'ticket', '42');
    $other = $signer->issue(EM_OTHER, 'ticket', '42');
    [$prefixT, $rest] = explode('.', $local, 2);
    [, $otherRest] = explode('.', $other, 2);

    expect($signer->verify(str_replace('ticket-42', 'ticket-43', $local)))->toBeNull()                 // other record
        ->and($signer->verify(str_replace('ticket-42', 'invoice-42', $local)))->toBeNull()             // other record type
        ->and($signer->verify($prefixT.'.'.$otherRest))->toBeNull()                                     // tenant A prefix with tenant B signature
        ->and($signer->verify(substr($local, 0, -1).(str_ends_with($local, 'a') ? 'b' : 'a')))->toBeNull() // one signature character
        ->and($signer->verify(substr($local, 0, -1)))->toBeNull()                                       // truncated
        ->and($signer->verify($local.'x'))->toBeNull()                                                  // extended
        ->and((new ReplyTokenSigner('key-two'))->verify($local))->toBeNull()                            // other application key
        ->and($signer->verify('reply+'.str_repeat('a', 26).'.ticket-42_'.str_repeat('a', 16)))->toBeNull() // forged outright
        ->and($signer->verify(''))->toBeNull()
        ->and($signer->verify('support'))->toBeNull()
        ->and($signer->verify("reply+x\n".$local))->toBeNull();
});

it('refuses to issue for invalid references', function (string $tenant, string $type, string $id) {
    expect(fn () => (new ReplyTokenSigner('k'))->issue($tenant, $type, $id))->toThrow(InvalidArgumentException::class);
})->with([
    'bad tenant' => ['not-a-uuid', 'ticket', '1'],
    'type too short' => [EM_TENANT, 't', '1'],
    'type too long' => [EM_TENANT, 'tickets', '1'],
    'type digits' => [EM_TENANT, 'tick3t', '1'],
    'id empty' => [EM_TENANT, 'ticket', ''],
    'id too long' => [EM_TENANT, 'ticket', 'abcdefgh'],
    'id punctuation' => [EM_TENANT, 'ticket', 'a_b'],
    'id upper case' => [EM_TENANT, 'ticket', 'ABC'],
]);

it('gives different addresses for different records (no sequential pattern in the signature)', function () {
    $s = new ReplyTokenSigner('k');
    $sigs = array_map(fn ($i) => substr($s->issue(EM_TENANT, 'ticket', (string) $i), -16), range(1, 20));

    expect(array_unique($sigs))->toHaveCount(20);
});

// ---- ip guard ----

it('classifies public and non-public addresses', function (string $ip, bool $public) {
    expect(IpGuard::isPublic($ip))->toBe($public);
})->with([
    ['8.8.8.8', true], ['93.184.216.34', true], ['2606:4700:4700::1111', true],
    ['127.0.0.1', false], ['10.1.2.3', false], ['172.16.0.1', false], ['172.31.255.255', false], ['172.32.0.1', true],
    ['192.168.1.1', false], ['169.254.169.254', false], ['100.64.0.1', false], ['0.0.0.0', false], ['224.0.0.1', false],
    ['::1', false], ['::', false], ['fe80::1', false], ['fd00::1', false], ['2001:db8::1', false],
    ['::ffff:127.0.0.1', false], ['::ffff:8.8.8.8', true], ['64:ff9b::7f00:1', false],
    ['not-an-ip', false], ['', false], ['999.1.1.1', false],
]);

// ---- smtp host guard ----

it('accepts a host that resolves only to public addresses and returns the IP to connect to', function () {
    $g = new SmtpHostGuard(fn () => ['93.184.216.34', '2606:2800::1']);

    expect($g->resolve('SMTP.Example.com ', 587))->toBe('93.184.216.34');
});

it('refuses hosts that point at private space, mixed answers, nothing, or are malformed', function (string $host, array $ips) {
    $g = new SmtpHostGuard(fn () => $ips);
    expect(fn () => $g->resolve($host, 587))->toThrow(InvalidArgumentException::class);
})->with([
    'resolves to loopback' => ['smtp.example.com', ['127.0.0.1']],
    'resolves to metadata' => ['smtp.example.com', ['169.254.169.254']],
    'mixed public+private (rebinding trick)' => ['smtp.example.com', ['93.184.216.34', '10.0.0.5']],
    'resolves to nothing' => ['smtp.example.com', []],
    'IP literal private' => ['192.168.0.10', []],
    'IPv6 loopback literal' => ['::1', []],
    'single label' => ['localhost', ['127.0.0.1']],
    'internal name' => ['db', ['10.0.0.1']],
    'with space' => ['smtp .example.com', ['93.184.216.34']],
    'embedded newline' => ["smtp.exa\nmple.com", ['93.184.216.34']],
    'empty' => ['', []],
]);

it('allows only standard submission ports', function (int $port, bool $ok) {
    $g = new SmtpHostGuard(fn () => ['93.184.216.34']);
    $ok ? expect($g->resolve('smtp.example.com', $port))->toBe('93.184.216.34') : expect(fn () => $g->resolve('smtp.example.com', $port))->toThrow(InvalidArgumentException::class);
})->with([[25, true], [465, true], [587, true], [2525, true], [22, false], [3306, false], [5432, false], [6379, false], [80, false], [0, false]]);

it('accepts a public IP literal without any DNS lookup', function () {
    $g = new SmtpHostGuard(fn () => throw new RuntimeException('no lookup expected'));

    expect($g->resolve('93.184.216.34', 25))->toBe('93.184.216.34');
});

// ---- smtp settings ----

it('validates a custom SMTP credential set', function () {
    $ok = ['smtp_host' => 'smtp.example.com', 'smtp_port' => '587', 'smtp_username' => 'u', 'smtp_password' => 'p'];

    expect(SmtpSettings::errors($ok))->toBe([])
        ->and(SmtpSettings::fromInput($ok)->port)->toBe(587)
        ->and(array_keys(SmtpSettings::errors([])))->toBe(['smtp_host', 'smtp_port', 'smtp_username', 'smtp_password'])
        ->and(SmtpSettings::errors(array_merge($ok, ['smtp_port' => '22'])))->toHaveKey('smtp_port')
        ->and(SmtpSettings::errors(array_merge($ok, ['smtp_username' => "a\r\nb"])))->toHaveKey('smtp_username');
});

// ---- sender identity ----

it('uses the platform address with the user\'s name and a signed Reply-To on the shared domain', function () {
    $r = SenderIdentity::resolve(false, null, 'no-reply@platform.test', 'Acme', 'Ada', 'ada@acme.test', 'reply+x@reply.test');

    expect($r)->toBe(['address' => 'no-reply@platform.test', 'name' => 'Ada via Acme', 'reply_to' => 'reply+x@reply.test']);
});

it('replies to the user directly on the shared domain when no thread is expected', function () {
    expect(SenderIdentity::resolve(false, null, 'no-reply@p.test', 'Acme', null, 'ada@acme.test', null))->toBe(['address' => 'no-reply@p.test', 'name' => 'Acme', 'reply_to' => 'ada@acme.test']);
});

it('sends as the user\'s own address through a tenant provider when the domain matches', function () {
    expect(SenderIdentity::resolve(true, 'noreply@Acme.test', 'no-reply@p.test', 'Acme', 'Ada', 'ada@acme.test', null))->toBe(['address' => 'ada@acme.test', 'name' => 'Ada', 'reply_to' => null]);
});

it('falls back to the configured sender when the user\'s address is on another domain', function () {
    expect(SenderIdentity::resolve(true, 'noreply@acme.test', 'no-reply@p.test', 'Acme', 'Ada', 'ada@gmail.test', null))
        ->toBe(['address' => 'noreply@acme.test', 'name' => 'Ada', 'reply_to' => 'ada@gmail.test']);
});

it('strips control characters and quotes from display names', function () {
    $r = SenderIdentity::resolve(false, null, 'n@p.test', "Ac\r\nme\"", "A<d>a\n", null, null);

    expect($r['name'])->toBe('Ada via Acme');
});

// ---- validator + composer ----

function emDto(array $o = []): OutboundEmailDTO
{
    return new OutboundEmailDTO(...array_merge(['tenantId' => EM_TENANT, 'toAddress' => 'to@example.com', 'subject' => 'Hello', 'textBody' => 'Body'], $o));
}

it('accepts a valid message', function () {
    expect(OutboundEmailValidator::validate(emDto(['replyRecordType' => 'ticket', 'replyRecordId' => '42'])))->toBeNull();
});

it('rejects header injection and malformed messages', function (array $override) {
    expect(fn () => OutboundEmailValidator::validate(emDto($override)))->toThrow(InvalidArgumentException::class);
})->with([
    'bad address' => [['toAddress' => 'nope']],
    'newline in address' => [['toAddress' => "a@example.com\nBcc: x@y.z"]],
    'newline in subject' => [['subject' => "Hi\r\nBcc: x@y.z"]],
    'empty subject' => [['subject' => '']],
    'subject too long' => [['subject' => str_repeat('a', 201)]],
    'newline in name' => [['toName' => "A\nB"]],
    'empty body' => [['textBody' => '  ']],
    'body too large' => [['textBody' => str_repeat('a', 200_001)]],
    'type without id' => [['replyRecordType' => 'ticket']],
    'bad record ref' => [['replyRecordType' => 'Ticket', 'replyRecordId' => '1']],
]);

it('composes a message with the chosen From, To, Reply-To, subject and bodies', function () {
    $m = EmailComposer::compose(emDto(['htmlBody' => '<p>Body</p>', 'toName' => 'Tess']), ['address' => 'from@p.test', 'name' => 'Ada via Acme', 'reply_to' => 'r@p.test']);

    expect($m->getFrom()[0]->getAddress())->toBe('from@p.test')->and($m->getFrom()[0]->getName())->toBe('Ada via Acme')
        ->and($m->getTo()[0]->getName())->toBe('Tess')->and($m->getReplyTo()[0]->getAddress())->toBe('r@p.test')
        ->and($m->getSubject())->toBe('Hello')->and($m->getTextBody())->toBe('Body')->and($m->getHtmlBody())->toBe('<p>Body</p>');
});
