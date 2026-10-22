<?php

declare(strict_types=1);

namespace App\Platform\Onboarding;

use App\Kernel\Dto;

final readonly class SignupDTO extends Dto
{
    public function __construct(
        public string $companyName,
        public string $adminName,
        public string $adminEmail,         // trimmed, lower-cased: the complete address is the uniqueness key
        #[\SensitiveParameter] public string $adminPassword,
        public string $subdomain,          // already normalised
        public string $businessType,
        public string $subscriptionPlan,   // plan key, validated against the plans table
        public string $billingCycle,       // monthly | annual
        public string $countryCode,        // upper-case ISO 3166-1 alpha-2
    ) {
    }
}
