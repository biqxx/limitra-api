<?php

use App\Http\Controllers\Api\Affiliate\DashboardController;
use Illuminate\Support\Facades\Route;

// All affiliate portal routes require a valid JWT + affiliate role.
Route::middleware(['auth:api', 'role:affiliate'])->prefix('affiliate')->name('affiliate.')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('referrals', [DashboardController::class, 'referrals'])->name('referrals');
    Route::get('sales', [DashboardController::class, 'sales'])->name('sales');
    Route::get('commissions', [DashboardController::class, 'commissions'])->name('commissions');
});
