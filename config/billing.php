<?php

/*
| Operator billing of tenants (control plane). Secrets come from the environment, never the database or git.
| A processor is usable only when its webhook secret is set; an unset secret makes every webhook for it fail verification.
*/
return [
    // Days a tenant stays Past Due (full access) before TenantRegistry suspends it.
    'grace_days' => (int) env('BILLING_GRACE_DAYS', 7),

    // Which processor bills a tenant, by billing currency. Null = no processor: plan changes apply immediately (manual billing).
    'processor_for_currency' => [
        'ZAR' => env('BILLING_PROCESSOR_ZAR'),   // e.g. paystack
        'USD' => env('BILLING_PROCESSOR_USD'),   // e.g. stripe
    ],

    'processors' => [
        'stripe' => [
            'webhook_secret' => env('BILLING_STRIPE_WEBHOOK_SECRET'),
            'api_key' => env('BILLING_STRIPE_API_KEY'),
            'tolerance_seconds' => 300,
        ],
        'chargebee' => [
            // Chargebee authenticates webhooks with HTTP Basic credentials you configure on the webhook.
            'webhook_username' => env('BILLING_CHARGEBEE_WEBHOOK_USERNAME'),
            'webhook_password' => env('BILLING_CHARGEBEE_WEBHOOK_PASSWORD'),
            'site' => env('BILLING_CHARGEBEE_SITE'),
            'api_key' => env('BILLING_CHARGEBEE_API_KEY'),
        ],
        'paystack' => [
            'webhook_secret' => env('BILLING_PAYSTACK_SECRET_KEY'),   // Paystack signs with your secret key
            'api_key' => env('BILLING_PAYSTACK_SECRET_KEY'),
            // "plan.CURRENCY.cycle" => Paystack plan code, e.g. 'starter.ZAR.monthly' => 'PLN_xxx'
            'plan_codes' => [],
        ],
    ],
];
