<?php

/*
| Platform-site JSON routes, loaded under the stateless `api` group with the /api prefix.
| REPLACES overlay 4. Signup is served ONLY on the platform root domain; the billing webhook ONLY on the admin host.
| No CSRF: public and stateless, protected by throttling instead.
*/

use App\Platform\Billing\Http\BillingWebhookController;
use App\Platform\Onboarding\Http\CreateTenantController;
use App\Tenancy\Middleware\ControlPlaneOnly;
use Illuminate\Support\Facades\Route;

Route::domain(config('tenancy.root_domain'))->group(function () {
    Route::post('/tenants', CreateTenantController::class)
        ->middleware('throttle:signup')
        ->name('api.tenants.create');
});

// Processor webhooks: stateless, no CSRF, authenticated by the processor's signature, never reachable from a tenant host.
Route::domain(config('tenancy.control_plane_host'))->middleware(ControlPlaneOnly::class)->group(function () {
    Route::post('/control-plane/billing/webhook', BillingWebhookController::class)
        ->middleware('throttle:600,1')
        ->name('api.billing.webhook');
});
