<?php

use App\Http\Controllers\Api\Admin\Analytics\ConversionController;
use App\Http\Controllers\Api\Admin\Analytics\CustomerController;
use App\Http\Controllers\Api\Admin\Analytics\OverviewController;
use App\Http\Controllers\Api\Admin\Analytics\ProductAnalyticsController;
use App\Http\Controllers\Api\Admin\Analytics\SalesController;
use App\Http\Controllers\Api\Admin\Analytics\TrafficController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'permission:analytics.read'])->prefix('analytics')->name('analytics.')->group(function () {
    Route::get('overview', OverviewController::class)->name('overview');
    Route::get('traffic', TrafficController::class)->name('traffic');
    Route::get('sales', SalesController::class)->name('sales');
    Route::get('conversion', ConversionController::class)->name('conversion');
    Route::get('products', ProductAnalyticsController::class)->name('products');
    Route::get('customers', CustomerController::class)->name('customers');
});
