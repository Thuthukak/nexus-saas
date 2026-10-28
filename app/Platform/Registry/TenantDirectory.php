<?php

declare(strict_types=1);

namespace App\Platform\Registry;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Read model for the operator's tenant list and detail screens (central tables only). */
final class TenantDirectory
{
    private const CUSTOM_DOMAIN = "(select d.domain from tenant_domains d where d.tenant_id = t.id and d.is_active and d.verified_at is not null order by d.created_at limit 1)";
    private const PLAN = "(select p.key from tenant_subscriptions s join plans p on p.id = s.plan_id where s.tenant_id = t.id order by s.created_at desc limit 1)";
    private const SUB_STATUS = "(select s.status from tenant_subscriptions s where s.tenant_id = t.id order by s.created_at desc limit 1)";

    public function search(?string $q, ?string $status, int $perPage = 25): LengthAwarePaginator
    {
        $query = DB::table('tenants as t')
            ->selectRaw('t.id, t.name, t.subdomain, t.status, t.country_code, t.billing_currency, t.database_connection, t.business_type, t.created_at')
            ->selectRaw(self::CUSTOM_DOMAIN.' as custom_domain')
            ->selectRaw(self::PLAN.' as plan')
            ->selectRaw(self::SUB_STATUS.' as subscription_status')
            ->orderByDesc('t.created_at')->orderBy('t.id');

        if (in_array($status, StatusTransition::STATUSES, true)) {
            $query->where('t.status', $status);
        }
        $q = trim((string) $q);
        if ($q !== '') {
            $like = '%'.addcslashes($q, '\\%_').'%';
            $query->where(fn ($w) => $w->where('t.name', 'ilike', $like)->orWhere('t.subdomain', 'ilike', $like)
                ->orWhereExists(fn ($e) => $e->selectRaw('1')->from('tenant_domains as d')->whereColumn('d.tenant_id', 't.id')->where('d.domain', 'ilike', $like)));
        }

        return $query->paginate($perPage)->withQueryString();
    }

    /** @return array<string, mixed>|null */
    public function detail(string $tenantId): ?array
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/iD', $tenantId) !== 1) {
            return null;
        }
        $tenant = DB::table('tenants')->where('id', $tenantId)->first();
        if ($tenant === null) {
            return null;
        }

        return [
            'tenant' => $tenant,
            'domains' => DB::table('tenant_domains')->where('tenant_id', $tenantId)->orderBy('created_at')->get(['domain', 'verified_at', 'is_active']),
            'subscription' => DB::table('tenant_subscriptions as s')->join('plans as p', 'p.id', '=', 's.plan_id')
                ->where('s.tenant_id', $tenantId)->orderByDesc('s.created_at')
                ->first(['p.key as plan', 'p.name as plan_name', 's.billing_cycle', 's.currency', 's.status', 's.current_period_end']),
            'history' => DB::table('tenant_status_events as e')->leftJoin('operators as o', 'o.id', '=', 'e.operator_id')
                ->where('e.tenant_id', $tenantId)->orderByDesc('e.id')->limit(20)
                ->get(['e.from_status', 'e.to_status', 'e.reason', 'e.actor', 'e.created_at', 'o.name as operator_name']),
        ];
    }
}
