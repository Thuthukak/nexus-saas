<?php

use App\Kernel\Dtos\OrganisationProfileUpdated;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

require_once __DIR__.'/CoreTestHelpers.php';
require_once __DIR__.'/CoreHttpHelpers.php';

uses( TestCase::class, DatabaseTransactions::class);

function orgData(array $o = []): array
{
    return array_merge([
        'organisation_name' => 'Acme Trading (Pty) Ltd', 'organisation_email' => 'Accounts@Acme.example',
        'organisation_phone' => '+27 11 555 0100', 'organisation_address' => '1 Main Road, Cape Town',
        'tax_registration_number' => '4123456789',
    ], $o);
}

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    [$this->idA, $this->adminA] = coreHttpTenant('alpha');
    [$this->idB, $this->adminB] = coreHttpTenant('beta');
});

it('creates the profile and returns OrganisationProfilePayload', function () {
    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', '/core/organisation'), orgData())
        ->assertOk()->assertJson([
            'organisation_name' => 'Acme Trading (Pty) Ltd', 'organisation_email' => 'accounts@acme.example',
            'organisation_address' => '1 Main Road, Cape Town',
        ]);
    expect(DB::table('organisation_profiles')->where('tenant_id', $this->idA)->count())->toBe(1);
});

it('keeps exactly one profile per tenant: a second save updates it', function () {
    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', '/core/organisation'), orgData())->assertOk();
    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', '/core/organisation'), orgData(['organisation_name' => 'Renamed']))->assertOk();

    expect(DB::table('organisation_profiles')->where('tenant_id', $this->idA)->count())->toBe(1)
        ->and(DB::table('organisation_profiles')->where('tenant_id', $this->idA)->value('name'))->toBe('Renamed');
});

it('has a database-level unique index: a second row for the same tenant is refused', function () {
    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', '/core/organisation'), orgData())->assertOk();

    expect(fn () => coreInSavepoint(fn () => DB::table('organisation_profiles')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->idA, 'name' => 'Dup', 'email' => 'd@example.com',
        'created_at' => now(), 'updated_at' => now(),
    ])))->toThrow(\Illuminate\Database\QueryException::class);
});

it('validates name, email, phone and tax number', function (array $override, string $field) {
    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', '/core/organisation'), orgData($override))
        ->assertStatus(422)->assertJsonPath('message', 'Validation failed')->assertJsonStructure(['errors' => [$field]]);
})->with([
    'no name' => [['organisation_name' => ''], 'organisation_name'],
    'bad email' => [['organisation_email' => 'nope'], 'organisation_email'],
    'bad phone' => [['organisation_phone' => 'call me maybe'], 'organisation_phone'],
    'bad tax number' => [['tax_registration_number' => '<script>'], 'tax_registration_number'],
]);

it('returns 403 without core.settings.manage', function () {
    app(\App\Tenancy\TenantContext::class)->set($this->idA);
    $plain = coreHttpPlainUser('alpha');

    $this->actingAs($plain, 'workspace')->postJson(coreHost('alpha', '/core/organisation'), orgData())
        ->assertForbidden()->assertJsonPath('message', 'Missing permission to manage the organisation profile');
});

it('broadcasts OrganisationProfileUpdated with the letterhead details', function () {
    Event::fake([OrganisationProfileUpdated::class]);

    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', '/core/organisation'), orgData())->assertOk();

    Event::assertDispatched(OrganisationProfileUpdated::class, fn ($e) => $e->tenantId() === $this->idA
        && $e->dto->name === 'Acme Trading (Pty) Ltd' && $e->dto->taxRegistrationNumber === '4123456789' && $e->dto->hasLogo === false);
});

it('stores the logo privately under the tenant\'s own folder and serves it back', function () {
    $this->actingAs($this->adminA, 'workspace')->post(coreHost('alpha', '/core/organisation'),
        orgData() + ['logo' => UploadedFile::fake()->image('../../evil name.png', 200, 100)], ['Accept' => 'application/json'])->assertOk()
        ->assertJsonPath('logo_url', fn ($u) => str_starts_with($u, '/core/organisation/logo'));

    $path = DB::table('organisation_profiles')->where('tenant_id', $this->idA)->value('logo_path');
    expect($path)->toStartWith("tenants/{$this->idA}/logo/")->and($path)->not->toContain('evil')
        ->and(Storage::disk('local')->exists($path))->toBeTrue();

    $this->actingAs($this->adminA, 'workspace')->get(coreHost('alpha', '/core/organisation/logo'))
        ->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('replaces the logo and removes the old file', function () {
    $post = fn () => $this->actingAs($this->adminA, 'workspace')->post(coreHost('alpha', '/core/organisation'),
        orgData() + ['logo' => UploadedFile::fake()->image('l.png')], ['Accept' => 'application/json'])->assertOk();

    $post();
    $first = DB::table('organisation_profiles')->where('tenant_id', $this->idA)->value('logo_path');
    $post();
    $second = DB::table('organisation_profiles')->where('tenant_id', $this->idA)->value('logo_path');

    expect($second)->not->toBe($first)->and(Storage::disk('local')->exists($first))->toBeFalse()
        ->and(Storage::disk('local')->exists($second))->toBeTrue();
});

it('rejects SVG, non-images and oversized logos', function (UploadedFile $file) {
    $this->actingAs($this->adminA, 'workspace')->post(coreHost('alpha', '/core/organisation'), orgData() + ['logo' => $file], ['Accept' => 'application/json'])
        ->assertStatus(422)->assertJsonStructure(['errors' => ['logo']]);
})->with([
    'svg' => fn () => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
    'php' => fn () => UploadedFile::fake()->createWithContent('logo.png', '<?php echo 1;'),
    'too big' => fn () => UploadedFile::fake()->image('big.png')->size(2000),
]);

it('never serves one tenant\'s logo to another', function () {
    $this->actingAs($this->adminA, 'workspace')->post(coreHost('alpha', '/core/organisation'),
        orgData() + ['logo' => UploadedFile::fake()->image('l.png')], ['Accept' => 'application/json'])->assertOk();

    $this->actingAs($this->adminB, 'workspace')->get(coreHost('beta', '/core/organisation/logo'))->assertNotFound();
});
