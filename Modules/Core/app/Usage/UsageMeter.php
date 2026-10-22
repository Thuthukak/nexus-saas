<?php

declare(strict_types=1);

namespace Modules\Core\Usage;

use App\Kernel\Dtos\UsageMetric;
use App\Kernel\Dtos\UsageRecordedDTO;
use App\Kernel\Dtos\UsageThresholdReached;
use App\Kernel\Dtos\UsageThresholdReachedDTO;
use Illuminate\Support\Facades\DB;
use Modules\Core\Models\User;
use Throwable;

/**
 * CoreUsageMetering. Runs inside a tenant request/job (RLS context already set).
 * Volume and ticket limits are SOFT: this class only counts and notifies, it never
 * rejects or deletes anything a customer submitted.
 *
 * Units: quantity metrics (tickets, seats) are plain integers; money metrics (processed
 * volume) are minor units of the tenant's billing currency, stored beside an explicit currency.
 */
final class UsageMeter
{
    /** @throws UsageCurrencyMismatch */
    public function record(UsageRecordedDTO $dto): void
    {
        $period = now('UTC')->format('Y-m');
        $isSeats = $dto->metric === UsageMetric::UserSeats;

        if ($dto->metric->isMoney() && $dto->currency !== $this->billingCurrency($dto->tenantId)) {
            throw new UsageCurrencyMismatch("Usage in {$dto->currency} does not match the tenant's billing currency.");
        }

        // Seats are measured, not summed: the row mirrors the live count of active workspace
        // users (so it can hold the notified flags). Other metrics add to the running total.
        $quantity = $isSeats ? $this->activeSeats() : $dto->quantity;
        $quantityOnConflict = $isSeats ? 'excluded.quantity' : 'usage_counters.quantity + excluded.quantity';

        // Atomic upsert: concurrent requests cannot lose an update. The WHERE refuses to add
        // across currencies (no row returned) rather than corrupt the running total.
        $row = DB::selectOne(
            "insert into usage_counters (tenant_id, metric, period, quantity, amount_minor, currency, created_at, updated_at)
             values (?, ?, ?, ?, ?, ?, now(), now())
             on conflict (tenant_id, metric, period) do update set
                 quantity = {$quantityOnConflict},
                 amount_minor = usage_counters.amount_minor + excluded.amount_minor,
                 updated_at = now()
             where usage_counters.currency is not distinct from excluded.currency
             returning quantity, amount_minor",
            [$dto->tenantId, $dto->metric->value, $period, $quantity, $dto->amountMinor, $dto->currency],
        );

        if ($row === null) {
            throw new UsageCurrencyMismatch('Existing counter for this period is in a different currency.');
        }

        $used = $dto->metric->isMoney() ? (int) $row->amount_minor : (int) $row->quantity;

        $this->notifyIfThresholdsReached($dto->tenantId, $dto->metric, $period, $used, $dto->currency);
    }

    public function activeSeats(): int
    {
        return User::query()->where('is_active', true)->count();
    }

    /** Current month's usage, for the subscription screen. Minor units for money metrics. */
    public function currentUsage(string $tenantId, UsageMetric $metric): int
    {
        if ($metric === UsageMetric::UserSeats) {
            return $this->activeSeats();
        }

        $column = $metric->isMoney() ? 'amount_minor' : 'quantity';

        return (int) DB::table('usage_counters')
            ->where('tenant_id', $tenantId)
            ->where('metric', $metric->value)
            ->where('period', now('UTC')->format('Y-m'))
            ->value($column);
    }

    /** True when the tenant has a subscription that grants access. */
    public function hasActiveSubscription(string $tenantId): bool
    {
        return $this->planLimits($tenantId) !== null;
    }

    /** @return int|null null = unlimited. Also null when there is no active subscription. */
    public function limitFor(string $tenantId, UsageMetric $metric): ?int
    {
        $limits = $this->planLimits($tenantId);
        if ($limits === null) {
            return null;
        }

        $value = match ($metric) {
            UsageMetric::UserSeats => $limits->seats,
            UsageMetric::SupportTickets => $limits->tickets_per_month,
            UsageMetric::FinancialVolume => $limits->volume_per_month_minor,
        };

        return $value === null ? null : (int) $value;
    }

    public function billingCurrency(string $tenantId): string
    {
        return (string) DB::table('tenants')->where('id', $tenantId)->value('billing_currency');
    }

    private function planLimits(string $tenantId): ?object
    {
        // Central tables (no RLS): tenant -> active subscription -> limits for its billing currency.
        return DB::table('tenants as t')
            ->join('tenant_subscriptions as s', 's.tenant_id', '=', 't.id')
            ->join('plan_limits as pl', fn ($j) => $j->on('pl.plan_id', '=', 's.plan_id')->on('pl.currency', '=', 't.billing_currency'))
            ->where('t.id', $tenantId)
            ->whereIn('s.status', ['active', 'past_due'])
            ->first(['pl.seats', 'pl.tickets_per_month', 'pl.volume_per_month_minor']);
    }

    private function notifyIfThresholdsReached(string $tenantId, UsageMetric $metric, string $period, int $used, ?string $currency): void
    {
        $limit = $this->limitFor($tenantId, $metric);
        $levels = UsageThresholds::levelsReached($used, $limit);

        if ($levels === []) {
            return;
        }

        // Claim each level once per month (atomic UPDATE ... WHERE notified IS NULL), so a
        // threshold produces exactly one notice even under concurrency. Notify for the
        // highest level newly claimed: jumping straight to 100% sends only the 100% notice.
        $highestNew = null;
        $claimedColumns = [];
        foreach ($levels as $level) {
            $column = $level === UsageThresholds::FULL ? 'notified_100_at' : 'notified_80_at';
            $claimed = DB::update(
                "update usage_counters set {$column} = now()
                 where tenant_id = ? and metric = ? and period = ? and {$column} is null",
                [$tenantId, $metric->value, $period],
            );
            if ($claimed > 0) {
                $highestNew = $level;
                $claimedColumns[] = $column;
            }
        }

        if ($highestNew === null) {
            return;
        }

        $dto = new UsageThresholdReachedDTO($tenantId, $metric, $highestNew, $used, (int) $limit, $period, $currency);

        try {
            // Own savepoint: if a listener fails, only the notification work is rolled back,
            // never the usage count that triggered it.
            DB::transaction(fn () => event(new UsageThresholdReached($dto)));
        } catch (Throwable $e) {
            report($e);

            // Release the claim so the next usage event retries the notice instead of losing it.
            foreach ($claimedColumns as $column) {
                DB::update(
                    "update usage_counters set {$column} = null where tenant_id = ? and metric = ? and period = ?",
                    [$tenantId, $metric->value, $period],
                );
            }
        }
    }
}
