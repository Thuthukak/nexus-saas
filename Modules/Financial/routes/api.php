<?php

use Illuminate\Support\Facades\Route;
use Modules\Financial\Http\Controllers\FinancialController;

Route::middleware(['auth:sanctum'])->prefix('v1')->group(function () {
    Route::apiResource('financials', FinancialController::class)->names('financial');
});
