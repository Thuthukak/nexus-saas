<?php

declare(strict_types=1);

namespace App\Platform\Onboarding;

use App\Kernel\Dto;

final readonly class ProvisionedTenantDTO extends Dto
{
    public function __construct(
        public string $tenantId,
        public string $subdomain,
        public string $workspaceUrl,
        public string $handoffUrl,
        public string $billingCurrency,
        public string $subscriptionPlan,
        public string $companyName,
        public string $adminName,
        public string $adminEmail,
    ) {
    }
}
