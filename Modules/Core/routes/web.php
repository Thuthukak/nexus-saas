<?php

/*
| REPLACES overlay 9. Adds the dashboard page, widget endpoints and reports. Core workspace routes: tenant host + signed-in workspace user only.
*/

use App\Tenancy\Middleware\TenantMiddleware;
use Illuminate\Support\Facades\Route;
use Modules\Core\Http\CustomerController;
use Modules\Core\Http\DashboardController;
use Modules\Core\Http\EmailIntegrationController;
use Modules\Core\Http\NotificationController;
use Modules\Core\Http\OrganisationController;
use Modules\Core\Http\RecordMetadataController;
use Modules\Core\Http\ReportController;
use Modules\Core\Http\TerminologyController;
use Modules\Core\Http\ThemeController;

Route::middleware(['web', TenantMiddleware::class, 'auth:workspace'])->get('/dashboard', [DashboardController::class, 'page'])->name('dashboard');
Route::middleware(['web', TenantMiddleware::class, 'auth:workspace'])->get('/reports', [ReportController::class, 'page'])->name('reports');

Route::middleware(['web', TenantMiddleware::class, 'auth:workspace'])->prefix('core')->group(function () {
    Route::get('theme', [ThemeController::class, 'show'])->name('core.theme');
    Route::post('theme', [ThemeController::class, 'update'])->middleware('throttle:30,1');

    Route::get('organisation', [OrganisationController::class, 'show'])->name('core.organisation');
    Route::post('organisation', [OrganisationController::class, 'update'])->middleware('throttle:30,1');
    Route::get('organisation/logo', [OrganisationController::class, 'logo']);

    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'read']);
    Route::get('notifications/preferences', [NotificationController::class, 'preferences']);
    Route::put('notifications/preferences', [NotificationController::class, 'setPreference']);

    Route::get('customers', [CustomerController::class, 'index'])->name('core.customers');
    Route::get('customers/{id}', [CustomerController::class, 'show'])->whereNumber('id')->name('core.customer');
    Route::post('customers', [CustomerController::class, 'store'])->middleware('throttle:120,1');
    Route::put('customers/{id}', [CustomerController::class, 'update'])->whereNumber('id')->middleware('throttle:120,1');

    Route::get('records/{entity}/{id}/metadata', [RecordMetadataController::class, 'read'])->where('entity', '[a-z][a-z0-9_]*');
    Route::post('records/{entity}/{id}/metadata', [RecordMetadataController::class, 'write'])->where('entity', '[a-z][a-z0-9_]*')->middleware('throttle:120,1');

    Route::get('email-integration', [EmailIntegrationController::class, 'show'])->name('core.email-integration');
    Route::post('email-integration', [EmailIntegrationController::class, 'update'])->middleware('throttle:10,1');

    Route::get('terminology', [TerminologyController::class, 'show'])->name('core.terminology');
    Route::put('terminology', [TerminologyController::class, 'update'])->middleware('throttle:30,1');
    Route::delete('terminology/{generic}', [TerminologyController::class, 'reset'])->middleware('throttle:30,1');

    Route::get('dashboard', [DashboardController::class, 'widgets']);
    Route::get('dashboard/widgets/customers', [DashboardController::class, 'customers']);
    Route::get('dashboard/widgets/customers-trend', [DashboardController::class, 'customersTrend']);

    Route::get('reports', [ReportController::class, 'show'])->middleware('throttle:60,1');
    Route::get('reports/metrics', [ReportController::class, 'metrics']);
});
