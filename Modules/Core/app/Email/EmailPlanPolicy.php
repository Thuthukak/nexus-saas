<?php

declare(strict_types=1);

namespace Modules\Core\Email;

use Illuminate\Support\Facades\DB;

/** Microsoft 365 and custom SMTP are Enterprise-only. Plans are data: the plan KEY decides, read from the central tables. */
final class EmailPlanPolicy
{
    public const ENTERPRISE_PLAN = 'enterprise';

    public function allows(string $tenantId, EmailProvider $provider): bool
    {
        if (! $provider->needsEnterprise()) {
            return true;
        }

        return DB::table('tenant_subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')
            ->where('s.tenant_id', $tenantId)->whereIn('s.status', ['active', 'past_due'])
            ->where('p.key', self::ENTERPRISE_PLAN)->exists();
    }
}
