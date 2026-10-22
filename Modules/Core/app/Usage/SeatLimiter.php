<?php

declare(strict_types=1);

namespace Modules\Core\Usage;

use App\Kernel\Dtos\UsageMetric;
use App\Kernel\Dtos\UsageRecorded;
use App\Kernel\Dtos\UsageRecordedDTO;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Hard seat limit. Every path that adds or reactivates a workspace user must go
 * through withSeat(): it locks the tenant row so two admins cannot both take the last seat.
 */
final class SeatLimiter
{
    public function __construct(private readonly UsageMeter $meter)
    {
    }

    /**
     * @template T
     * @param  callable():T  $createOrActivateUser
     * @return T
     *
     * @throws SeatLimitReached
     */
    public function withSeat(string $tenantId, callable $createOrActivateUser): mixed
    {
        return DB::transaction(function () use ($tenantId, $createOrActivateUser) {
            // Serialises concurrent seat additions for this tenant only.
            DB::table('tenants')->where('id', $tenantId)->lockForUpdate()->first();

            if (! $this->meter->hasActiveSubscription($tenantId)) {
                throw new RuntimeException('No active subscription: activate a plan before adding users.');
            }

            $limit = $this->meter->limitFor($tenantId, UsageMetric::UserSeats);
            if ($limit !== null && $this->meter->activeSeats() >= $limit) {
                throw new SeatLimitReached($limit);
            }

            $result = $createOrActivateUser();

            // Re-measure seats and send the 80% / 100% notices if a threshold was just reached.
            event(new UsageRecorded(UsageRecordedDTO::ofQuantity($tenantId, UsageMetric::UserSeats, 1)));

            return $result;
        });
    }
}
