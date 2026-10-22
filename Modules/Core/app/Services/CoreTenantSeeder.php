<?php

declare(strict_types=1);

namespace Modules\Core\Services;

use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Modules\Core\Permissions\PermissionManager;

/**
 * Seeds Core data for a NEW tenant (called by signup provisioning).
 * Sets the tenant context itself so RLS accepts the inserts.
 */
final class CoreTenantSeeder
{
    /** Starter permission set for the Tenant Admin role. Modules add their own keys later. */
    public const TENANT_ADMIN_PERMISSIONS = [
        'core.users.manage',
        'core.roles.manage',
        'core.settings.manage',
        'core.theme.manage',
        'core.organisation.manage',
        'core.modules.manage',
        'core.subscription.manage',
        'core.customers.manage',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly PermissionManager $permissions,
    ) {
    }

    public function seed(string $tenantId): void
    {
        DB::transaction(function () use ($tenantId) {
            $this->context->set($tenantId);

            $this->permissions->ensureSuperAdmin();

            $this->permissions->upsertRole('Tenant Admin');
            foreach (self::TENANT_ADMIN_PERMISSIONS as $key) {
                $this->permissions->attach('Tenant Admin', $key);
            }
        });
    }
}
