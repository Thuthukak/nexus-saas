<?php

declare(strict_types=1);

namespace App\Platform\Modules;

use Illuminate\Support\Facades\DB;

/**
 * What a tenant is allowed to use: modules in its active plan plus paid add-ons.
 * Plans and add-ons are data (rule 27), never code.
 * Central tables, so no RLS and no tenant context needed.
 */
final class Entitlements
{
    /** Subscription states that still grant access. past_due keeps a grace period. */
    private const ACCESS_STATES = ['active', 'past_due'];

    /** @return list<string> */
    public function entitledModules(string $tenantId): array
    {
        $included = DB::table('tenant_subscriptions as s')
            ->join('plan_modules as pm', 'pm.plan_id', '=', 's.plan_id')
            ->where('s.tenant_id', $tenantId)
            ->whereIn('s.status', self::ACCESS_STATES)
            ->pluck('pm.module_key')
            ->all();

        $addons = DB::table('tenant_addons')
            ->where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->pluck('module_key')
            ->all();

        return array_values(array_unique([...$included, ...$addons, ModuleKey::Core->value]));
    }

    public function isEntitled(string $tenantId, string $module): bool
    {
        return in_array($module, $this->entitledModules($tenantId), true);
    }
}
