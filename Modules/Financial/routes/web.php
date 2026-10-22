<?php

use Illuminate\Support\Facades\Route;
use Modules\Financial\Http\Controllers\FinancialController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('financials', FinancialController::class)->names('financial');
});
