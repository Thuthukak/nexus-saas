<?php

use App\Kernel\Dtos\NotificationRequested;
use App\Kernel\Dtos\NotificationRequestedDTO;
use App\Kernel\Dtos\UsageMetric;
use App\Kernel\Dtos\UsageThresholdReached;
use App\Kernel\Dtos\UsageThresholdReachedDTO;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Core\Notifications\CoreNotification;
use Modules\Core\Notifications\Mail\NotificationMail;
use Modules\Core\Notifications\NotificationTenantMismatch;
use Modules\Core\Notifications\NotificationTypeRegistry;
use Modules\Core\Notifications\Notifier;
use Tests\TestCase;

require_once __DIR__.'/CoreTestHelpers.php';
require_once __DIR__.'/CoreHttpHelpers.php';

uses( TestCase::class, DatabaseTransactions::class);

function ntDto(string $tenantId, int $userId, string $type = 'test.ping', string $title = 'Ping'): NotificationRequestedDTO
{
    return new NotificationRequestedDTO($tenantId, $userId, $type, $title, 'Body text', '{"ticket":7}');
}

beforeEach(function () {
    Mail::fake();
    $types = app(NotificationTypeRegistry::class);
    $types->register('test.ping');                              // in-app only by default
    $types->register('test.mail', inApp: true, email: true);   // in-app + email by default
    $types->register('test.quiet', inApp: false, email: false);

    [$this->idA, $this->adminA] = coreHttpTenant('alpha');
    $this->plainA = coreHttpPlainUser('alpha');
    [$this->idB, $this->adminB] = coreHttpTenant('beta');
    app(TenantContext::class)->set($this->idA);
    Mail::fake();
});

// ---- Notifier ----

it('writes an in-app notification for a user of the tenant', function () {
    $n = app(Notifier::class)->notify(ntDto($this->idA, $this->adminA->id));

    expect($n)->not->toBeNull()->and($n->tenant_id)->toBe($this->idA)->and($n->user_id)->toBe($this->adminA->id)
        ->and($n->data)->toBe(['ticket' => 7])->and($n->read_at)->toBeNull();
    Mail::assertNothingQueued();
});

it('rejects unregistered types', function () {
    expect(fn () => app(Notifier::class)->notify(ntDto($this->idA, $this->adminA->id, 'made.up')))->toThrow(InvalidArgumentException::class);
});

it('never notifies across tenants: DTO tenant mismatch, foreign user, and the composite FK', function () {
    $notifier = app(Notifier::class);

    expect(fn () => $notifier->notify(ntDto($this->idB, $this->adminB->id)))->toThrow(NotificationTenantMismatch::class);   // context is A
    expect(fn () => $notifier->notify(ntDto($this->idA, $this->adminB->id)))->toThrow(NotificationTenantMismatch::class);   // user belongs to B

    // Even a direct insert cannot pair tenant A with tenant B's user.
    expect(fn () => coreInSavepoint(fn () => DB::table('core_notifications')->insert([
        'id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->idA, 'user_id' => $this->adminB->id,
        'type' => 'test.ping', 'title' => 'x', 'created_at' => now(),
    ])))->toThrow(\Illuminate\Database\QueryException::class);

    expect(DB::table('core_notifications')->count())->toBe(0);
});

it('queues an email when the type defaults to the email channel', function () {
    app(Notifier::class)->notify(ntDto($this->idA, $this->adminA->id, 'test.mail', 'Heads up'));

    Mail::assertQueued(NotificationMail::class, fn ($m) => $m->hasTo('alpha@example.com') && $m->subjectLine === 'Heads up');
});

it('honours per-user channel preferences', function () {
    $notifier = app(Notifier::class);
    $notifier->setPreference($this->adminA->id, 'test.mail', inApp: false, email: false);
    $notifier->setPreference($this->adminA->id, 'test.ping', inApp: true, email: true);

    expect($notifier->notify(ntDto($this->idA, $this->adminA->id, 'test.mail')))->toBeNull();   // in-app off
    Mail::assertNothingQueued();                                                                // email off

    $notifier->notify(ntDto($this->idA, $this->adminA->id, 'test.ping'));
    Mail::assertQueued(NotificationMail::class, 1);                                             // email switched on
    expect(CoreNotification::count())->toBe(1);
});

it('does not email a deactivated user', function () {
    $this->plainA->forceFill(['is_active' => false])->save();

    app(Notifier::class)->notify(ntDto($this->idA, $this->plainA->id, 'test.mail'));

    Mail::assertNothingQueued();
});

it('is delivered by event under the event\'s own tenant even with no ambient tenant', function () {
    app(TenantContext::class)->clear();

    event(new NotificationRequested(ntDto($this->idA, $this->adminA->id)));

    expect(app(TenantContext::class)->id())->toBeNull();   // restored
    app(TenantContext::class)->set($this->idA);
    expect(CoreNotification::count())->toBe(1);
});

it('tells every Tenant Admin when a usage limit threshold is reached', function () {
    event(new UsageThresholdReached(new UsageThresholdReachedDTO($this->idA, UsageMetric::SupportTickets, 80, 400, 500, '2026-10')));

    expect(CoreNotification::where('type', 'core.usage_threshold')->where('user_id', $this->adminA->id)->count())->toBe(1)
        ->and(CoreNotification::where('user_id', $this->plainA->id)->count())->toBe(0);
    Mail::assertQueued(NotificationMail::class, fn ($m) => $m->hasTo('alpha@example.com'));
});

// ---- HTTP ----

it('lists only the signed-in user\'s notifications with the unread count', function () {
    $notifier = app(Notifier::class);
    $notifier->notify(ntDto($this->idA, $this->adminA->id, 'test.ping', 'Mine 1'));
    $notifier->notify(ntDto($this->idA, $this->adminA->id, 'test.ping', 'Mine 2'));
    $notifier->notify(ntDto($this->idA, $this->plainA->id, 'test.ping', 'Not mine'));
    CoreNotification::where('title', 'Mine 1')->update(['read_at' => now()]);

    $this->actingAs($this->adminA, 'workspace')->getJson(coreHost('alpha', '/core/notifications'))
        ->assertOk()->assertJsonPath('unread_count', 1)->assertJsonCount(2, 'notifications')
        ->assertJsonMissing(['title' => 'Not mine']);
});

it('marks one notification read, but only your own', function () {
    $mine = app(Notifier::class)->notify(ntDto($this->idA, $this->adminA->id));
    $theirs = app(Notifier::class)->notify(ntDto($this->idA, $this->plainA->id));

    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', "/core/notifications/{$theirs->id}/read"))->assertNotFound();
    coreAsTenant($this->idA);
    expect(CoreNotification::find($theirs->id)->read_at)->toBeNull();

    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', "/core/notifications/{$mine->id}/read"))->assertOk();
    coreAsTenant($this->idA);
    expect(CoreNotification::find($mine->id)->read_at)->not->toBeNull();
});

it('marks all of your notifications read without touching other users', function () {
    app(Notifier::class)->notify(ntDto($this->idA, $this->adminA->id));
    app(Notifier::class)->notify(ntDto($this->idA, $this->plainA->id));

    $this->actingAs($this->adminA, 'workspace')->postJson(coreHost('alpha', '/core/notifications/read-all'))->assertOk();
    coreAsTenant($this->idA);

    expect(CoreNotification::where('user_id', $this->adminA->id)->whereNull('read_at')->count())->toBe(0)
        ->and(CoreNotification::where('user_id', $this->plainA->id)->whereNull('read_at')->count())->toBe(1);
});

it('never shows tenant A notifications to tenant B', function () {
    app(Notifier::class)->notify(ntDto($this->idA, $this->adminA->id));

    $this->actingAs($this->adminB, 'workspace')->getJson(coreHost('beta', '/core/notifications'))
        ->assertOk()->assertJsonPath('unread_count', 0)->assertJsonCount(0, 'notifications');
});

it('lets a user set and read back their channel preferences', function () {
    $this->actingAs($this->adminA, 'workspace')->putJson(coreHost('alpha', '/core/notifications/preferences'), ['type' => 'test.ping', 'in_app' => true, 'email' => true])->assertOk();

    $this->actingAs($this->adminA, 'workspace')->getJson(coreHost('alpha', '/core/notifications/preferences'))
        ->assertOk()->assertJsonFragment(['type' => 'test.ping', 'in_app' => true, 'email' => true]);

    $this->actingAs($this->adminA, 'workspace')->putJson(coreHost('alpha', '/core/notifications/preferences'), ['type' => 'bogus', 'in_app' => true, 'email' => true])
        ->assertStatus(422)->assertJsonPath('message', 'Validation failed');
});

it('requires a signed-in user', function () {
    $this->getJson(coreHost('alpha', '/core/notifications'))->assertUnauthorized();
});
