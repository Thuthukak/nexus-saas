<?php

declare(strict_types=1);

namespace App\Kernel\Contracts;

use App\Kernel\Dtos\TenantAdminDTO;

/** Lets the control plane reach a tenant's administrators without touching Core's tables. */
interface TenantAdminDirectory
{
    /** @return list<TenantAdminDTO> active users holding the Tenant Admin role */
    public function activeAdmins(string $tenantId): array;
}
