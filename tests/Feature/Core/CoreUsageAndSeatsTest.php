<?php

use App\Kernel\Dtos\UsageMetric;
use App\Kernel\Dtos\UsageRecorded;
use App\Kernel\Dtos\UsageRecordedDTO;
use App\Kernel\Dtos\UsageThresholdReached;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Core\Models\User;
use Modules\Core\Usage\SeatLimitReached;
use Modules\Core\Usage\SeatLimiter;
use Modules\Core\Usage\UsageMeter;
use Tests\TestCase;

require_once __DIR__.'/CoreTestHelpers.php';

uses(TestCase::class, DatabaseTransactions::class);

function coreRecordQuantity(string $tenantId, UsageMetric $metric, int $quantity): void
{
    event(new UsageRecorded(UsageRecordedDTO::ofQuantity($tenantId, $metric, $quantity)));
}

function coreRecordMoney(string $tenantId, int $amountMinor, string $currency): void
{
    event(new UsageRecorded(UsageRecordedDTO::ofMoney($tenantId, UsageMetric::FinancialVolume, $amountMinor, $currency)));
}

function coreAddUser(string $tenantId, string $email): User
{
    return app(SeatLimiter::class)->withSeat($tenantId, fn () => User::create([
        'name' => 'U', 'email' => $email, 'password' => 'secret-pass-1',
    ]));
}

// ---- quantity vs money are separate by construction ----

it('refuses to mix quantity and money in a DTO', function () {
    expect(fn () => UsageRecordedDTO::ofQuantity('t', UsageMetric::FinancialVolume, 5))->toThrow(InvalidArgumentException::class, 'is money')
        ->and(fn () => UsageRecordedDTO::ofMoney('t', UsageMetric::SupportTickets, 5, 'ZAR'))->toThrow(InvalidArgumentException::class, 'is a quantity')
        ->and(fn () => UsageRecordedDTO::ofMoney('t', UsageMetric::FinancialVolume, 5, 'zar'))->toThrow(InvalidArgumentException::class, 'Currency')
        ->and(fn () => UsageRecordedDTO::ofQuantity('t', UsageMetric::SupportTickets, -1))->toThrow(InvalidArgumentException::class, 'negative');
});

it('refuses to store a quantity and money on the same counter row (database CHECK)', function () {
    $t = coreMakeTenant();
    coreActAs($t);

    $row = fn (array $over) => array_merge([
        'tenant_id' => $t->id, 'metric' => 'x', 'period' => '2026-10', 'quantity' => 0,
        'amount_minor' => 0, 'currency' => null, 'created_at' => now(), 'updated_at' => now(),
    ], $over);

    expect(fn () => coreInSavepoint(fn () => DB::table('usage_counters')->insert($row(['quantity' => 1, 'amount_minor' => 500, 'currency' => 'ZAR']))))
        ->toThrow(QueryException::class)
        ->and(fn () => coreInSavepoint(fn () => DB::table('usage_counters')->insert($row(['amount_minor' => 500]))))   // money, no currency
        ->toThrow(QueryException::class);
});

// ---- counting ----

it('adds ticket usage atomically and keeps one counter row per month', function () {
    $t = coreMakeTenant('starter');
    coreActAs($t);

    coreRecordQuantity($t->id, UsageMetric::SupportTickets, 3);
    coreRecordQuantity($t->id, UsageMetric::SupportTickets, 4);

    $row = DB::table('usage_counters')->where('tenant_id', $t->id)->first();
    expect(app(UsageMeter::class)->currentUsage($t->id, UsageMetric::SupportTickets))->toBe(7)
        ->and(DB::table('usage_counters')->where('tenant_id', $t->id)->count())->toBe(1)
        ->and($row->amount_minor)->toBe(0)->and($row->currency)->toBeNull();
});

it('stores processed volume as minor units beside its currency', function () {
    $t = coreMakeTenant('starter', 'ZAR');
    coreActAs($t);

    coreRecordMoney($t->id, 1_500_000, 'ZAR');
    coreRecordMoney($t->id, 2_500_000, 'ZAR');

    $row = DB::table('usage_counters')->where(['tenant_id' => $t->id, 'metric' => 'financial_volume'])->first();
    expect($row->amount_minor)->toBe(4_000_000)->and(trim($row->currency))->toBe('ZAR')->and($row->quantity)->toBe(0)
        ->and(app(UsageMeter::class)->currentUsage($t->id, UsageMetric::FinancialVolume))->toBe(4_000_000);
});

it('never blocks a payment: a wrong-currency usage event is reported and ignored, totals untouched', function () {
    $t = coreMakeTenant('starter', 'ZAR');
    coreActAs($t);
    coreRecordMoney($t->id, 1_000, 'ZAR');

    coreRecordMoney($t->id, 999_999, 'USD');   // must not throw into the payment flow

    expect(app(UsageMeter::class)->currentUsage($t->id, UsageMetric::FinancialVolume))->toBe(1_000);
});

// ---- soft limits: warn, never reject ----

it('warns once at 80% and once at 100% of the ticket allowance, and never rejects', function () {
    $t = coreMakeTenant('starter'); // 50 tickets / month
    coreActAs($t);
    Event::fake([UsageThresholdReached::class]);   // only this event is faked; listeners still run

    coreRecordQuantity($t->id, UsageMetric::SupportTickets, 39);
    Event::assertNotDispatched(UsageThresholdReached::class);

    coreRecordQuantity($t->id, UsageMetric::SupportTickets, 1);   // 40 = 80%
    coreRecordQuantity($t->id, UsageMetric::SupportTickets, 1);   // 41: no repeat notice
    Event::assertDispatchedTimes(UsageThresholdReached::class, 1);

    coreRecordQuantity($t->id, UsageMetric::SupportTickets, 9);   // 50 = 100%
    Event::assertDispatchedTimes(UsageThresholdReached::class, 2);

    coreRecordQuantity($t->id, UsageMetric::SupportTickets, 20);  // way over: still counted, no more notices
    expect(app(UsageMeter::class)->currentUsage($t->id, UsageMetric::SupportTickets))->toBe(70);
    Event::assertDispatchedTimes(UsageThresholdReached::class, 2);
    Event::assertDispatched(UsageThresholdReached::class, fn ($e) => $e->dto->percent === 100 && $e->dto->limit === 50 && $e->dto->currency === null);
});

it('measures volume against the limit in the tenant billing currency and reports the currency', function () {
    $zar = coreMakeTenant('starter', 'ZAR');
    coreActAs($zar);
    expect(app(UsageMeter::class)->limitFor($zar->id, UsageMetric::FinancialVolume))->toBe(5_000_000);   // R50,000

    $usd = coreMakeTenant('starter', 'USD');
    coreActAs($usd);
    expect(app(UsageMeter::class)->limitFor($usd->id, UsageMetric::FinancialVolume))->toBe(300_000);     // $3,000

    Event::fake([UsageThresholdReached::class]);
    coreActAs($zar);
    coreRecordMoney($zar->id, 4_000_000, 'ZAR');   // R40,000 of R50,000 = 80%
    Event::assertDispatched(UsageThresholdReached::class, fn ($e) => $e->dto->percent === 80 && $e->dto->currency === 'ZAR');
});

it('treats Enterprise as unlimited and never notifies', function () {
    $t = coreMakeTenant('enterprise');
    coreActAs($t);
    Event::fake([UsageThresholdReached::class]);

    coreRecordQuantity($t->id, UsageMetric::SupportTickets, 100_000);

    Event::assertNotDispatched(UsageThresholdReached::class);
    expect(app(UsageMeter::class)->limitFor($t->id, UsageMetric::SupportTickets))->toBeNull();
});

// ---- hard seat limit ----

it('enforces the hard seat limit and counts only active workspace users', function () {
    $t = coreMakeTenant('starter'); // 2 seats
    coreActAs($t);

    $first = coreAddUser($t->id, 'one@example.com');
    coreAddUser($t->id, 'two@example.com');

    expect(fn () => coreAddUser($t->id, 'three@example.com'))->toThrow(SeatLimitReached::class, 'allows 2 workspace users')
        ->and(User::count())->toBe(2);

    $first->update(['is_active' => false]);   // deactivating frees a seat
    coreAddUser($t->id, 'three@example.com');
    expect(User::where('is_active', true)->count())->toBe(2);
});

it('works on a plan with a seat limit of 1: the admin is seat 1 and a second user is refused', function () {
    $key = coreEnsurePlan('solo-test', seats: 1);
    $t = coreMakeTenant($key);
    coreActAs($t);

    $admin = coreAddUser($t->id, 'admin@example.com');   // the signup admin takes the only seat

    expect($admin->fresh()->is_active)->toBeTrue()
        ->and(app(UsageMeter::class)->activeSeats())->toBe(1)
        ->and(fn () => coreAddUser($t->id, 'second@example.com'))->toThrow(SeatLimitReached::class, 'allows 1 workspace users')
        ->and(User::count())->toBe(1);
});

it('records seat usage so the 100% notice fires when the last seat is taken', function () {
    $t = coreMakeTenant('starter'); // 2 seats
    coreActAs($t);
    Event::fake([UsageThresholdReached::class]);

    coreAddUser($t->id, 'one@example.com');   // 1 of 2 = 50%: nothing
    Event::assertNotDispatched(UsageThresholdReached::class);

    coreAddUser($t->id, 'two@example.com');   // 2 of 2 = 100%
    Event::assertDispatched(UsageThresholdReached::class, fn ($e) => $e->dto->metric === UsageMetric::UserSeats && $e->dto->percent === 100);
});

it('does not limit seats on Enterprise', function () {
    $t = coreMakeTenant('enterprise');
    coreActAs($t);

    foreach (range(1, 15) as $i) {
        coreAddUser($t->id, "u{$i}@example.com");
    }

    expect(User::count())->toBe(15);
});

it('refuses to add a seat when the tenant has no active subscription', function () {
    $t = coreMakeTenant('starter');
    DB::table('tenant_subscriptions')->where('tenant_id', $t->id)->update(['status' => 'cancelled']);
    coreActAs($t);

    expect(fn () => app(SeatLimiter::class)->withSeat($t->id, fn () => null))
        ->toThrow(RuntimeException::class, 'No active subscription');
});

// ---- isolation ----

it('isolates usage counters and users between tenants (RLS)', function () {
    $a = coreMakeTenant('starter');
    $b = coreMakeTenant('starter');

    coreActAs($a);
    coreRecordQuantity($a->id, UsageMetric::SupportTickets, 5);
    User::create(['name' => 'A', 'email' => 'only-a@example.com', 'password' => 'secret-pass-1']);

    coreActAs($b);
    expect(DB::table('usage_counters')->count())->toBe(0)
        ->and(DB::table('users')->count())->toBe(0);
});

it('rejects a duplicate full email in the platform directory, but not a shared domain', function () {
    $a = coreMakeTenant();
    $b = coreMakeTenant();
    $row = fn ($tenant, $email) => ['email_normalised' => $email, 'tenant_id' => $tenant->id, 'created_at' => now(), 'updated_at' => now()];

    DB::table('platform_emails')->insert($row($a, 'sam@gmail.com'));
    DB::table('platform_emails')->insert($row($b, 'alex@gmail.com'));   // same domain: allowed

    expect(fn () => coreInSavepoint(fn () => DB::table('platform_emails')->insert($row($b, 'sam@gmail.com'))))   // same address: rejected
        ->toThrow(QueryException::class);
});

it('keeps the usage count when a threshold listener fails', function () {
    $t = coreMakeTenant('starter');   // 50 tickets / month
    coreActAs($t);
    Event::listen(UsageThresholdReached::class, fn () => throw new RuntimeException('listener down'));

    coreRecordQuantity($t->id, UsageMetric::SupportTickets, 40);   // exactly 80%

    expect(app(UsageMeter::class)->currentUsage($t->id, UsageMetric::SupportTickets))->toBe(40);
});
