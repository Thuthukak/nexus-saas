<?php

declare(strict_types=1);

namespace App\Platform\Billing;

/**
 * Pure pricing maths. All amounts are integers in minor units (cents).
 *
 * Spec: annual monthly-equivalent = 80% of the monthly price, rounded DOWN to a
 * whole currency unit; the annual charge is twelve times that equivalent.
 */
final class PlanPricing
{
    private const MINOR_PER_UNIT = 100;

    public static function annualMonthlyEquivalent(int $monthlyMinor): int
    {
        // intdiv(x * 80, 100) = 80% in minor units; then floor to whole units.
        $eightyPercent = intdiv($monthlyMinor * 80, 100);

        return intdiv($eightyPercent, self::MINOR_PER_UNIT) * self::MINOR_PER_UNIT;
    }

    public static function annualCharge(int $monthlyMinor): int
    {
        return self::annualMonthlyEquivalent($monthlyMinor) * 12;
    }

    /** ZAR for South Africa, USD for everyone else. */
    public static function currencyForCountry(string $countryCode): string
    {
        return strtoupper(trim($countryCode)) === 'ZA' ? 'ZAR' : 'USD';
    }
}
