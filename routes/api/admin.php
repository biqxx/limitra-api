<?php

use App\Http\Controllers\Api\Admin\AffiliateController as AdminAffiliateController;
use App\Http\Controllers\Api\Admin\CommissionController as AdminCommissionController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use Illuminate\Support\Facades\Route;

// ── Order management ──────────────────────────────────────────────────────────
Route::middleware(['auth:api', 'role:admin,staff'])->prefix('orders')->name('admin.orders.')->group(function () {
    Route::get('/', [AdminOrderController::class, 'index'])->name('index');
    Route::get('{order}', [AdminOrderController::class, 'show'])->name('show');
});

// ── Affiliate management ──────────────────────────────────────────────────────
// Read: admin + staff   Write: admin only
Route::middleware(['auth:api', 'role:admin,staff'])->prefix('affiliates')->name('admin.affiliates.')->group(function () {
    Route::get('/', [AdminAffiliateController::class, 'index'])->name('index');
    Route::get('{affiliate}', [AdminAffiliateController::class, 'show'])->name('show');
    Route::get('{affiliate}/commissions', [AdminAffiliateController::class, 'commissions'])->name('commissions');
});

Route::middleware(['auth:api', 'role:admin'])->prefix('affiliates')->name('admin.affiliates.')->group(function () {
    Route::post('/', [AdminAffiliateController::class, 'store'])->name('store');
    Route::patch('{affiliate}', [AdminAffiliateController::class, 'update'])->name('update');
});

// ── Commission management ─────────────────────────────────────────────────────
Route::middleware(['auth:api', 'role:admin,staff'])->prefix('commissions')->name('admin.commissions.')->group(function () {
    Route::get('/', [AdminCommissionController::class, 'index'])->name('index');
    Route::get('{commission}', [AdminCommissionController::class, 'show'])->name('show');
});

Route::middleware(['auth:api', 'role:admin'])->prefix('commissions')->name('admin.commissions.')->group(function () {
    Route::patch('{commission}', [AdminCommissionController::class, 'update'])->name('update');
    Route::post('bulk-update', [AdminCommissionController::class, 'bulkUpdate'])->name('bulk-update');
});
