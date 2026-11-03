<?php

declare(strict_types=1);

namespace App\Platform\Billing;

final readonly class SubscriptionSyncRequest
{
    public function __construct(
        public string $tenantId,
        public string $planKey,
        public string $planName,
        public string $cycle,
        public string $currency,
        public int $chargeMinor,
        public string $customerEmail,
        public string $customerName,
        public ?string $existingReference = null,
    ) {
    }
}
