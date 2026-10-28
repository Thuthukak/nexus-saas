<?php

declare(strict_types=1);

namespace App\Platform\Registry;

use App\Kernel\Dtos\TenantStatusChanged;
use App\Kernel\Dtos\TenantStatusChangedDTO;
use App\Tenancy\Tenant;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * TenantRegistry: the one place a tenant's status changes. TenantMiddleware reads the same `tenants` rows on every
 * request, so a change takes effect on the very next request, with nothing cached in between. Data is never deleted.
 */
final class TenantRegistry
{
    public function __construct(private readonly TenantStatusNotifier $notifier)
    {
    }

    /**
     * @param int|null $operatorId the operator making the change, or null when the platform itself does (billing engine)
     * @throws TenantNotFound|InvalidStatusTransition|InvalidArgumentException
     */
    public function changeStatus(string $tenantId, string $to, string $reason, ?int $operatorId = null): Tenant
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw new InvalidArgumentException('A reason of 1 to 500 characters is required.');
        }
        if (! in_array($to, StatusTransition::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown status [{$to}].");
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $tenantId) !== 1) {
            throw new TenantNotFound();
        }

        [$tenant, $from, $changed] = DB::transaction(function () use ($tenantId, $to, $reason, $operatorId): array {
            /** @var Tenant|null $tenant */
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->first() ?? throw new TenantNotFound();
            $from = $tenant->status;

            if ($from === $to) {
                return [$tenant, $from, false];   // already there: idempotent, no second email, no second audit row
            }
            if (! StatusTransition::allowed($from, $to)) {
                throw new InvalidStatusTransition($from, $to);
            }

            $tenant->status = $to;
            $tenant->save();
            DB::table('tenant_status_events')->insert([
                'tenant_id' => $tenant->id, 'from_status' => $from, 'to_status' => $to, 'reason' => $reason,
                'actor' => $operatorId === null ? 'system' : 'operator', 'operator_id' => $operatorId, 'created_at' => now(),
            ]);

            return [$tenant, $from, true];
        });

        if ($changed) {
            event(new TenantStatusChanged(new TenantStatusChangedDTO($tenant->id, $from, $to, $reason)));
            $this->notifier->notify($tenant, $to, $reason);
        }

        return $tenant;
    }
}
