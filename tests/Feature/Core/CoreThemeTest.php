<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Settings\SettingRegistry;
use Modules\Core\Theming\ThemeService;
use Tests\TestCase;

require_once __DIR__.'/CoreTestHelpers.php';
require_once __DIR__.'/CoreHttpHelpers.php';

uses(TestCase::class, DatabaseTransactions::class);

const GOOD = ['primary_colour' => '#1d4ed8', 'secondary_colour' => '#334155', 'accent_colour' => '#0f766e'];

beforeEach(function () {
    Mail::fake();
    [$this->idA, $this->adminA] = coreHttpTenant('alpha');
    [$this->idB, $this->adminB] = coreHttpTenant('beta');
});

it('saves a valid palette and returns ThemeUpdatedPayload', function () {
    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', '/core/theme'), GOOD)
        ->assertOk()->assertJson(GOOD + ['warnings' => []]);

    app(\App\Tenancy\TenantContext::class)->set($this->idA);
    expect(app(ThemeService::class)->current()->primary)->toBe('#1d4ed8');
});

it('normalises short hex codes', function () {
    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', '/core/theme'), ['primary_colour' => '#146', 'secondary_colour' => '#334155', 'accent_colour' => '#0f766e'])
        ->assertOk()->assertJsonPath('primary_colour', '#114466');
});

it('returns 422 "Validation failed" for malformed colours and stores nothing', function () {
    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', '/core/theme'), ['primary_colour' => 'blue', 'secondary_colour' => '#334155'])
        ->assertStatus(422)->assertJsonPath('message', 'Validation failed')
        ->assertJsonStructure(['errors' => ['primary_colour', 'accent_colour']]);

    app(\App\Tenancy\TenantContext::class)->set($this->idA);
    expect(app(ThemeService::class)->current()->primary)->toBe(ThemeService::DEFAULTS['primary_colour']);
});

it('refuses a low-contrast palette until the warning is acknowledged', function () {
    $low = ['primary_colour' => '#1d4ed8', 'secondary_colour' => '#334155', 'accent_colour' => '#ffff00'];

    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', '/core/theme'), $low)
        ->assertStatus(422)->assertJsonPath('message', 'Validation failed')
        ->assertJsonPath('warnings.0.token', 'accent_colour')->assertJsonStructure(['errors' => ['acknowledge_contrast']]);

    coreAsTenant($this->idA);
    expect(app(ThemeService::class)->current()->accent)->not->toBe('#ffff00');

    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', '/core/theme'), $low + ['acknowledge_contrast' => true])
        ->assertOk()->assertJsonPath('warnings.0.token', 'accent_colour');
    coreAsTenant($this->idA);
    expect(app(ThemeService::class)->current()->accent)->toBe('#ffff00');
});

it('returns 403 to a user without core.settings.manage', function () {
    app(\App\Tenancy\TenantContext::class)->set($this->idA);
    $plain = coreHttpPlainUser('alpha');

    $this->actingAs($plain, 'workspace')->postJson(coreHost('alpha', '/core/theme'), GOOD)
        ->assertForbidden()->assertJsonPath('message', 'Missing permission to manage settings');
});

it('requires a signed-in user', function () {
    $this->postJson(coreHost('alpha', '/core/theme'), GOOD)->assertUnauthorized();
});

it('keeps each tenant\'s palette separate', function () {
    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', '/core/theme'), GOOD)->assertOk();

    app(\App\Tenancy\TenantContext::class)->set($this->idB);
    expect(app(ThemeService::class)->current()->primary)->toBe(ThemeService::DEFAULTS['primary_colour']);
});

it('shares the theme as an Inertia prop (also on the public login page), with on_* tokens', function () {
    $this->get(coreHost('alpha', '/login'))->assertOk()->assertInertia(
        fn ($p) => $p->component('Auth/Login')->where('theme.primary_colour', ThemeService::DEFAULTS['primary_colour'])->has('theme.on_primary'),
    );

    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', '/core/theme'), GOOD)->assertOk();

    $this->actingAs($this->adminA, 'workspace')->get(coreHost('alpha', '/dashboard'))->assertInertia(
        fn ($p) => $p->where('theme.primary_colour', '#1d4ed8')->where('tenant.subdomain', 'alpha')
            ->where('auth.user.email', 'alpha@example.com')->where('modules', ['Core', 'Financial', 'Support'])
            ->where('auth.permissions', fn ($perms) => collect($perms)->contains('core.settings.manage')),
    );
});
