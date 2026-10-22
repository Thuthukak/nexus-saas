<?php

/*
| Core routes served on the PLATFORM ROOT DOMAIN (not a tenant host): no tenant resolution, no signed-in user.
*/

use Illuminate\Support\Facades\Route;
use Modules\Core\Http\MicrosoftConsentController;

Route::middleware(['web', 'throttle:30,1'])->domain(config('tenancy.root_domain'))->group(function () {
    Route::get('/integrations/microsoft/callback', MicrosoftConsentController::class)->name('integrations.microsoft.callback');
});
