<?php

/*
| REPLACES the overlay-5 file (overlay 9 removes the /dashboard stub). Adds workspace login/logout, dashboard stub and root redirect.
| Loaded inside the `web` middleware group (session, cookies) from bootstrap/app.php.
*/

use App\Tenancy\Auth\LoginController;
use App\Tenancy\ControlPlane\TlsAskController;
use App\Tenancy\Handoff\HandoffController;
use App\Tenancy\Middleware\ControlPlaneOnly;
use App\Tenancy\Middleware\TenantMiddleware;
use Illuminate\Support\Facades\Route;

$controlHost = config('tenancy.control_plane_host');

// Caddy on-demand TLS `ask` endpoint. Internal only (bind to localhost / firewall it).
Route::get('/internal/tls-ask', TlsAskController::class)->name('internal.tls-ask');

// Control plane: ONLY on the admin host.
Route::domain($controlHost)->middleware(ControlPlaneOnly::class)->group(function () {
    // Operator routes registered in the control-plane stage.
});

// Data plane: every other host. TenantMiddleware 404s anything that does not resolve.
Route::middleware(TenantMiddleware::class)->group(function () {
    Route::get('/auth/handoff', HandoffController::class)->middleware('throttle:30,1')->name('auth.handoff');

    Route::get('/', fn () => redirect('/dashboard'));
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->middleware('throttle:20,1');

    Route::middleware('auth:workspace')->group(function () {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
        // /dashboard now lives in Core (Modules/Core/routes/web.php): widgets are assembled there.
    });

    // Core / Financial / Support module routes are registered by their own service providers.
});
