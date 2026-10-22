<?php

declare(strict_types=1);

namespace Modules\Core\Permissions;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/** Tenant Admin holds exactly the permissions of the modules enabled for its tenant. Idempotent. */
final class TenantAdminGrants
{
    public function __construct(
        private readonly PermissionCatalogue $catalogue,
        private readonly PermissionManager $perms,
        private readonly TenantContext $context,
    ) {
    }

    public function sync(string $tenantId): void
    {
        $enabled = DB::table('tenant_modules')
            ->where('tenant_id', $tenantId)->where('is_enabled', true)
            ->pluck('module_key')->all();

        // Core is mandatory and can never be disabled, so it counts as enabled even
        // before its tenant_modules row exists (e.g. a tenant created without signup).
        $modules = array_values(array_unique(['Core', ...$enabled]));

        $previous = $this->context->id();

        DB::transaction(function () use ($tenantId, $modules) {
            $this->context->set($tenantId);
            $this->perms->ensureSuperAdmin();
            $this->perms->upsertRole('Tenant Admin');

            foreach ($this->catalogue->forModules($modules) as $key) {
                $this->perms->attach('Tenant Admin', $key);
            }
        });

        $previous !== null ? $this->context->set($previous) : $this->context->clear();
    }
}
