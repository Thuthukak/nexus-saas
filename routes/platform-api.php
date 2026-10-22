<?php

/*
| Platform-site JSON routes, loaded under the stateless `api` group with the /api prefix.
| Served ONLY on the platform root domain (never a tenant host, never the control plane).
| No CSRF: public and stateless, protected by throttling instead.
*/

use App\Platform\Onboarding\Http\CreateTenantController;
use Illuminate\Support\Facades\Route;

Route::domain(config('tenancy.root_domain'))->group(function () {
    Route::post('/tenants', CreateTenantController::class)
        ->middleware('throttle:signup')
        ->name('api.tenants.create');
});
