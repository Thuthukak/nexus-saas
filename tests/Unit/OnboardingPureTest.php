<?php

use App\Platform\Onboarding\Subdomain;
use App\Tenancy\Handoff\HandoffSigner;
use App\Tenancy\TenantUrl;

it('normalises subdomains to lowercase letters, digits and hyphens', function (string $requested, string $company, string $expected) {
    expect(Subdomain::normalise($requested, $company))->toBe($expected);
})->with([
    'plain'                    => ['acme', 'Whatever', 'acme'],
    'uppercase and spaces'     => ['ACME Corp', 'x', 'acme-corp'],
    'punctuation'              => ['a.c.m.e!!', 'x', 'a-c-m-e'],
    'repeated hyphens'         => ['a---b', 'x', 'a-b'],
    'leading/trailing hyphens' => ['--acme--', 'x', 'acme'],
    'blank falls back to company' => ['', 'My Company (Pty) Ltd', 'my-company-pty-ltd'],
    'spaces only fall back'    => ['   ', 'Penda Graphics', 'penda-graphics'],
    'both blank'               => ['', '', ''],
]);

it('enforces DNS-friendly length', function () {
    expect(Subdomain::hasValidLength('ab'))->toBeFalse()
        ->and(Subdomain::hasValidLength('abc'))->toBeTrue()
        ->and(Subdomain::hasValidLength(str_repeat('a', 63)))->toBeTrue()
        ->and(Subdomain::hasValidLength(str_repeat('a', 64)))->toBeFalse();
});

it('recognises reserved subdomains', function () {
    $reserved = ['www', 'api', 'admin', 'app', 'mail', 'reply', 'control', 'status'];

    foreach ($reserved as $name) {
        expect(Subdomain::isReserved($name, $reserved))->toBeTrue();
    }
    expect(Subdomain::isReserved('acme', $reserved))->toBeFalse();
});

it('signs and verifies a handoff token, binding tenant, user, nonce and expiry', function () {
    $signer = HandoffSigner::fromAppKey('base64:test-key');
    $tenant = '11111111-1111-1111-1111-111111111111';
    $nonce = str_repeat('ab', 32);
    $exp = 1_900_000_000;
    $sig = $signer->sign($tenant, 7, $nonce, $exp);

    expect($signer->verify($sig, $tenant, 7, $nonce, $exp))->toBeTrue()
        ->and($signer->verify($sig, '22222222-2222-2222-2222-222222222222', 7, $nonce, $exp))->toBeFalse() // other tenant
        ->and($signer->verify($sig, $tenant, 8, $nonce, $exp))->toBeFalse()                               // other user
        ->and($signer->verify($sig, $tenant, 7, str_repeat('cd', 32), $exp))->toBeFalse()                 // other nonce
        ->and($signer->verify($sig, $tenant, 7, $nonce, $exp + 3600))->toBeFalse()                        // extended expiry
        ->and($signer->verify(str_repeat('0', 64), $tenant, 7, $nonce, $exp))->toBeFalse()                // forged signature
        ->and(HandoffSigner::fromAppKey('another-key')->verify($sig, $tenant, 7, $nonce, $exp))->toBeFalse() // other app key
        ->and($signer->verify($sig, $tenant.'|9', 7, $nonce, $exp))->toBeFalse();                          // delimiter smuggling
});

it('builds workspace URLs for production and local dev', function () {
    expect(TenantUrl::build('https', 'pendagraphics.co.za', null, 'acme', '/auth/handoff'))
        ->toBe('https://acme.pendagraphics.co.za/auth/handoff')
        ->and(TenantUrl::build('http', 'localhost', 8000, 'acme'))
        ->toBe('http://acme.localhost:8000/');
});
