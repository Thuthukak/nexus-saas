<?php

namespace Database\Seeders;

use App\Platform\Billing\PlanPricing;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Idempotent: safe to re-run after changing a price or limit. */
class PlanCatalogueSeeder extends Seeder
{
    public function run(): void
    {
        // monthly price in minor units per currency; null limit = unlimited
        $plans = [
            'starter' => [
                'name' => 'Starter', 'order' => 1, 'seats' => 2, 'tickets' => 50, 'domain' => false, 'from' => false,
                'volume' => ['ZAR' => 5_000_000, 'USD' => 300_000],       // R50,000 / $3,000
                'price' => ['ZAR' => 24_900, 'USD' => 1_900],             // R249 / $19
            ],
            'professional' => [
                'name' => 'Professional', 'order' => 2, 'seats' => 10, 'tickets' => 500, 'domain' => false, 'from' => false,
                'volume' => ['ZAR' => 50_000_000, 'USD' => 3_000_000],    // R500,000 / $30,000
                'price' => ['ZAR' => 69_900, 'USD' => 4_900],             // R699 / $49
            ],
            'enterprise' => [
                'name' => 'Enterprise', 'order' => 3, 'seats' => null, 'tickets' => null, 'domain' => true, 'from' => true,
                'volume' => ['ZAR' => null, 'USD' => null],
                'price' => ['ZAR' => 249_900, 'USD' => 14_900],           // from R2,499 / from $149
            ],
        ];

        DB::transaction(function () use ($plans) {
            foreach ($plans as $key => $p) {
                DB::table('plans')->updateOrInsert(
                    ['key' => $key],
                    ['name' => $p['name'], 'sort_order' => $p['order'], 'is_active' => true, 'updated_at' => now(), 'created_at' => now()],
                );
                $planId = DB::table('plans')->where('key', $key)->value('id');

                // Every plan includes Core, Financial and Support (rule 22).
                foreach (['Core', 'Financial', 'Support'] as $module) {
                    DB::table('plan_modules')->updateOrInsert(['plan_id' => $planId, 'module_key' => $module], []);
                }

                foreach ($p['price'] as $currency => $monthly) {
                    DB::table('plan_limits')->updateOrInsert(
                        ['plan_id' => $planId, 'currency' => $currency],
                        [
                            'seats' => $p['seats'],
                            'tickets_per_month' => $p['tickets'],
                            'volume_per_month_minor' => $p['volume'][$currency],
                            'custom_domain_allowed' => $p['domain'],
                        ],
                    );

                    DB::table('plan_prices')->updateOrInsert(
                        ['plan_id' => $planId, 'currency' => $currency, 'cycle' => 'monthly'],
                        ['monthly_equivalent_minor' => $monthly, 'charge_minor' => $monthly, 'is_starting_from' => $p['from']],
                    );
                    DB::table('plan_prices')->updateOrInsert(
                        ['plan_id' => $planId, 'currency' => $currency, 'cycle' => 'annual'],
                        [
                            'monthly_equivalent_minor' => PlanPricing::annualMonthlyEquivalent($monthly),
                            'charge_minor' => PlanPricing::annualCharge($monthly),
                            'is_starting_from' => $p['from'],
                        ],
                    );
            }
            }

            // CoreModuleDependencyRegistry: every non-Core module requires Core only.
            foreach (['Financial', 'Support'] as $module) {
                DB::table('module_dependencies')->updateOrInsert(['module_key' => $module, 'requires_key' => 'Core'], []);
            }
        });
    }
}
