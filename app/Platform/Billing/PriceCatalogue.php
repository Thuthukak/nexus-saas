<?php

declare(strict_types=1);

namespace App\Platform\Billing;

use Illuminate\Support\Facades\DB;

/** Prices come from plan_prices (data, rule 27). Enterprise is a starting price; custom_price_minor overrides it per tenant. */
final class PriceCatalogue
{
    /** @return array{charge_minor:int, is_starting_from:bool}|null */
    public function quote(string $planKey, string $currency, string $cycle, ?int $customPriceMinor = null): ?array
    {
        $row = DB::table('plan_prices as pp')->join('plans as p', 'p.id', '=', 'pp.plan_id')
            ->where('p.key', $planKey)->where('p.is_active', true)->where('pp.currency', $currency)->where('pp.cycle', $cycle)
            ->first(['pp.charge_minor', 'pp.is_starting_from']);
        if ($row === null) {
            return null;
        }
        $custom = $row->is_starting_from && $customPriceMinor !== null && $customPriceMinor >= (int) $row->charge_minor ? $customPriceMinor : null;

        return ['charge_minor' => $custom ?? (int) $row->charge_minor, 'is_starting_from' => (bool) $row->is_starting_from && $custom === null];
    }
}
