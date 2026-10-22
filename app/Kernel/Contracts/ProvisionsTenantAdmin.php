<?php

declare(strict_types=1);

namespace App\Kernel\Contracts;

use App\Kernel\Dtos\ProvisionTenantAdminDTO;
use App\Kernel\Dtos\TenantAdminProvisionedDTO;

/**
 * Seam between the control plane (tenant provisioning) and Core (users, roles, seats).
 * The control plane depends on this contract and typed DTOs, never on Core classes;
 * Core implements and binds it. Implementations run inside the caller's transaction.
 */
interface ProvisionsTenantAdmin
{
    public function provision(ProvisionTenantAdminDTO $dto): TenantAdminProvisionedDTO;
}
