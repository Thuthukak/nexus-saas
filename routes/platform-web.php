<?php

/*
| Platform site pages (web group: session, cookies, Inertia). Root domain ONLY.
| REMOVE the default `Route::get('/', ...)` welcome route from routes/web.php.
*/

use App\Platform\Onboarding\Http\SignupPageController;
use Illuminate\Support\Facades\Route;

Route::domain(config('tenancy.root_domain'))->group(function () {
    Route::get('/', SignupPageController::class)->name('platform.signup');
});
