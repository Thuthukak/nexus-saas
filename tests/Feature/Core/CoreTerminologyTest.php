<?php

use App\Kernel\Contracts\TerminologyResolver;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Parties\Party;
use Tests\TestCase;

require_once __DIR__.'/CoreTestHelpers.php';
require_once __DIR__.'/CoreHttpHelpers.php';

uses( TestCase::class, DatabaseTransactions::class);

function termsFor(string $tenantId, string $businessType): void
{
    DB::table('tenants')->where('id', $tenantId)->update(['business_type' => $businessType]);
    Cache::flush();
    app(TenantContext::class)->set($tenantId);
}

beforeEach(function () {
    Mail::fake();
    [$this->idA, $this->adminA] = coreHttpTenant('alpha');
    $this->plainA = coreHttpPlainUser('alpha');
    [$this->idB, $this->adminB] = coreHttpTenant('beta');
    $this->resolver = fn () => app(TerminologyResolver::class);
});

it('maps the business type to its vocabulary, falling back to the generic name', function (string $type, string $one, string $many) {
    termsFor($this->idA, $type);

    expect(($this->resolver)()->resolve('Party'))->toBe($one)->and(($this->resolver)()->resolve('Party', plural: true))->toBe($many);
})->with([
    ['Healthcare', 'Patient', 'Patients'], ['Education', 'Student', 'Students'], ['Professional Services', 'Client', 'Clients'],
    ['Retail', 'Customer', 'Customers'], ['Other', 'Customer', 'Customers'],
    ['Spaceship repair', 'Party', 'Parties'],   // no mapping: the generic term
    ['', 'Party', 'Parties'],
]);

it('passes unregistered generic names through untouched', function () {
    termsFor($this->idA, 'Healthcare');

    expect(($this->resolver)()->resolve('Invoice'))->toBe('Invoice')->and(($this->resolver)()->resolve('Invoice', plural: true))->toBe('Invoices');
});

it('lets the tenant override the default, and reset restores it', function () {
    termsFor($this->idA, 'Healthcare');
    $put = fn (array $d) => $this->actingAs($this->adminA, 'workspace')->putJson(coreHost('alpha', '/core/terminology'), $d);

    $put(['generic_term' => 'Party', 'display_term' => 'Member'])->assertOk()
        ->assertJson(['generic_term' => 'Party', 'singular' => 'Member', 'plural' => 'Members', 'source' => 'tenant']);
    coreAsTenant($this->idA);
    expect(($this->resolver)()->resolve('Party'))->toBe('Member');

    $put(['generic_term' => 'Party', 'display_term' => 'Congregant', 'display_plural' => 'Congregation'])->assertOk()->assertJsonPath('plural', 'Congregation');

    $this->actingAs($this->adminA, 'workspace')->deleteJson(coreHost('alpha', '/core/terminology/Party'))->assertOk()
        ->assertJson(['singular' => 'Patient', 'source' => 'business_type']);
});

it('validates display terms and the generic name (422)', function (array $d) {
    $this->actingAs($this->adminA, 'workspace')->putJson(coreHost('alpha', '/core/terminology'), $d)
        ->assertStatus(422)->assertJsonPath('message', 'Validation failed');
})->with([
    'markup' => [['generic_term' => 'Party', 'display_term' => '<b>Hi</b>']],
    'digits' => [['generic_term' => 'Party', 'display_term' => 'Client 2']],
    'too long' => [['generic_term' => 'Party', 'display_term' => str_repeat('a', 41)]],
    'empty' => [['generic_term' => 'Party', 'display_term' => '']],
    'unknown generic' => [['generic_term' => 'Table', 'display_term' => 'Customer']],
    'bad plural' => [['generic_term' => 'Party', 'display_term' => 'Client', 'display_plural' => 'Cl;ients']],
]);

it('requires core.settings.manage', function () {
    $this->actingAs($this->plainA, 'workspace')->putJson(coreHost('alpha', '/core/terminology'), ['generic_term' => 'Party', 'display_term' => 'Member'])
        ->assertForbidden()->assertJsonPath('message', 'Missing permission to manage terminology');
    $this->actingAs($this->plainA, 'workspace')->deleteJson(coreHost('alpha', '/core/terminology/Party'))->assertForbidden();
    coreAsGuest();
    $this->putJson(coreHost('alpha', '/core/terminology'), [])->assertUnauthorized();
});

it('keeps one tenant\'s wording away from another (including the cache)', function () {
    termsFor($this->idA, 'Retail');
    $this->actingAs($this->adminA, 'workspace')->putJson(coreHost('alpha', '/core/terminology'), ['generic_term' => 'Party', 'display_term' => 'Member'])->assertOk();

    termsFor($this->idB, 'Healthcare');
    expect(($this->resolver)()->resolve('Party'))->toBe('Patient');
    app(TenantContext::class)->set($this->idA);
    expect(($this->resolver)()->resolve('Party'))->toBe('Member');
});

it('shares the resolved vocabulary as an Inertia prop on every tenant page', function () {
    termsFor($this->idA, 'Healthcare');

    $this->actingAs($this->adminA, 'workspace')->get(coreHost('alpha', '/core/customers'))->assertInertia(
        fn ($p) => $p->where('terms.Party.singular', 'Patient')->where('terms.Party.plural', 'Patients')->where('terms.Party.source', 'business_type'),
    );
});

it('changes labels only: the Core table and the data are untouched', function () {
    termsFor($this->idA, 'Healthcare');
    Party::create(['name' => 'Pat', 'email' => 'pat@example.com']);
    $this->actingAs($this->adminA, 'workspace')->putJson(coreHost('alpha', '/core/terminology'), ['generic_term' => 'Party', 'display_term' => 'Member'])->assertOk();
    coreAsTenant($this->idA);

    expect(Schema::hasTable('core_parties'))->toBeTrue()->and(Schema::hasTable('core_patients'))->toBeFalse()->and(Schema::hasTable('core_members'))->toBeFalse()
        ->and(Party::count())->toBe(1);
});

it('ships the platform defaults as reference data in the migration', function () {
    expect(DB::table('terminology_mappings')->where('business_type_key', 'healthcare')->where('generic_term', 'Party')->value('singular'))->toBe('Patient')
        ->and(DB::table('terminology_mappings')->count())->toBeGreaterThanOrEqual(10);
});
