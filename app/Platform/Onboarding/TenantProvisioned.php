<?php

declare(strict_types=1);

namespace App\Platform\Onboarding;

/** Dispatched AFTER the provisioning transaction commits (welcome email, billing engine, analytics). */
final class TenantProvisioned
{
    public function __construct(public readonly ProvisionedTenantDTO $dto)
    {
    }
}
