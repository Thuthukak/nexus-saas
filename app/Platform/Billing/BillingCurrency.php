<?php

declare(strict_types=1);

namespace App\Platform\Billing;

final class BillingCurrency
{
    public const ZAR = 'ZAR';
    public const USD = 'USD';

    /** ZAR when the business is in South Africa, otherwise USD. */
    public static function forCountry(string $countryCode): string
    {
        return strtoupper(trim($countryCode)) === 'ZA' ? self::ZAR : self::USD;
    }
}
