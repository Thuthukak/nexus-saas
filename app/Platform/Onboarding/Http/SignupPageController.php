<?php

declare(strict_types=1);

namespace App\Platform\Onboarding\Http;

use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Public signup page on the platform root domain. Plans and prices come from the database. */
final class SignupPageController
{
    public function __invoke(): Response
    {
        $plans = DB::table('plans')->where('is_active', true)->orderBy('sort_order')->get();
        $prices = DB::table('plan_prices')->get()->groupBy('plan_id');
        $limits = DB::table('plan_limits')->get()->groupBy('plan_id');
        $modules = DB::table('plan_modules')->get()->groupBy('plan_id');

        return Inertia::render('Platform/Signup', [
            'plans' => $plans->map(fn ($p) => [
                'key' => $p->key,
                'name' => $p->name,
                'modules' => ($modules[$p->id] ?? collect())->pluck('module_key')->values(),
                // Minor units; the client formats. prices[currency][cycle] = {monthly_equivalent, charge}
                'prices' => ($prices[$p->id] ?? collect())->groupBy('currency')->map(fn ($rows) => $rows->mapWithKeys(fn ($r) => [
                    $r->cycle => [
                        'monthly_equivalent' => (int) $r->monthly_equivalent_minor,
                        'charge' => (int) $r->charge_minor,
                        'starting_from' => (bool) $r->is_starting_from,
                    ],
                ])),
                'limits' => ($limits[$p->id] ?? collect())->mapWithKeys(fn ($l) => [
                    $l->currency => [
                        'seats' => $l->seats,
                        'tickets_per_month' => $l->tickets_per_month,
                        'volume_per_month_minor' => $l->volume_per_month_minor,
                        'custom_domain' => (bool) $l->custom_domain_allowed,
                    ],
                ]),
            ])->values(),
            'rootDomain' => config('tenancy.root_domain'),
        ]);
    }
}
