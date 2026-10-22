<?php

use App\Platform\Billing\PlanPricing;

it('matches the spec annual monthly-equivalents (80% rounded down to a whole unit)', function (int $monthly, int $equivalent) {
    expect(PlanPricing::annualMonthlyEquivalent($monthly))->toBe($equivalent);
})->with([
    'Starter ZAR'      => [24_900, 19_900],   // R249  -> R199
    'Starter USD'      => [1_900, 1_500],     // $19   -> $15
    'Professional ZAR' => [69_900, 55_900],   // R699  -> R559
    'Professional USD' => [4_900, 3_900],     // $49   -> $39
    'Enterprise ZAR'   => [249_900, 199_900], // R2499 -> R1999
    'Enterprise USD'   => [14_900, 11_900],   // $149  -> $119
]);

it('charges twelve times the equivalent for an annual plan', function () {
    expect(PlanPricing::annualCharge(24_900))->toBe(19_900 * 12)
        ->and(PlanPricing::annualCharge(1_900))->toBe(1_500 * 12);
});

it('bills ZAR for South Africa and USD for everyone else', function () {
    expect(PlanPricing::currencyForCountry('ZA'))->toBe('ZAR')
        ->and(PlanPricing::currencyForCountry(' za '))->toBe('ZAR')
        ->and(PlanPricing::currencyForCountry('US'))->toBe('USD')
        ->and(PlanPricing::currencyForCountry('NA'))->toBe('USD');
});
