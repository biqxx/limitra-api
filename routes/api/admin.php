<?php

use App\Http\Controllers\Api\Admin\AdminDashboardController;
use App\Http\Controllers\Api\Admin\AffiliateController as AdminAffiliateController;
use App\Http\Controllers\Api\Admin\AffiliateOperationsAdminController;
use App\Http\Controllers\Api\Admin\CategoryController as AdminCategoryController;
use App\Http\Controllers\Api\Admin\CommissionController as AdminCommissionController;
use App\Http\Controllers\Api\Admin\OrderController as AdminOrderController;
use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\ProductController as CatalogProductController;
use Illuminate\Support\Facades\Route;

// ── Categories & Catalog Products ───────────────────────────────────────────
Route::middleware(['auth:api', 'active.session', 'role:admin'])
    ->get('categories', [AdminCategoryController::class, 'index'])
    ->name('admin.categories.index');

Route::middleware(['auth:api', 'active.session', 'role:admin'])
    ->prefix('products')
    ->name('admin.products.')
    ->group(function (): void {
        Route::get('/', [AdminProductController::class, 'index'])->name('index');
        Route::post('/', [CatalogProductController::class, 'store'])->name('store');
        Route::post('{product}/restore', [AdminProductController::class, 'restore'])
            ->withTrashed()
            ->name('restore');
        Route::get('{product}', [AdminProductController::class, 'show'])
            ->withTrashed()
            ->name('show');
        Route::match(['put', 'patch'], '{product}', [CatalogProductController::class, 'update'])->name('update');
        Route::delete('{product}', [CatalogProductController::class, 'destroy'])->name('destroy');
    });

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

Route::middleware(['auth:api', 'active.session', 'role:admin'])->prefix('commissions')->name('admin.commissions.')->group(function () {
    Route::patch('{commission}', [AdminCommissionController::class, 'update'])->name('update');
    Route::post('bulk-update', [AdminCommissionController::class, 'bulkUpdate'])->name('bulk-update');
});

// ── Affiliate Applications ──────────────────────────────────────────────────
Route::middleware(['auth:api', 'active.session', 'role:admin,staff'])->prefix('affiliate-applications')->name('admin.affiliate-applications.')->group(function (): void {
    Route::get('/', [AffiliateOperationsAdminController::class, 'applications'])->name('index');
    Route::get('{application}', [AffiliateOperationsAdminController::class, 'showApplication'])->name('show');
});

Route::middleware(['auth:api', 'active.session', 'role:admin'])->prefix('affiliate-applications')->name('admin.affiliate-applications.')->group(function (): void {
    Route::post('{application}/approve', [AffiliateOperationsAdminController::class, 'approveApplication'])->name('approve');
    Route::post('{application}/reject', [AffiliateOperationsAdminController::class, 'rejectApplication'])->name('reject');
});

// ── Affiliate Withdrawals ───────────────────────────────────────────────────
Route::middleware(['auth:api', 'active.session', 'role:admin,staff'])->prefix('withdrawals')->name('admin.withdrawals.')->group(function (): void {
    Route::get('/', [AffiliateOperationsAdminController::class, 'withdrawals'])->name('index');
});

Route::middleware(['auth:api', 'active.session', 'role:admin'])->prefix('withdrawals')->name('admin.withdrawals.')->group(function (): void {
    Route::post('{withdrawal}/approve', [AffiliateOperationsAdminController::class, 'approveWithdrawal'])->name('approve');
    Route::post('{withdrawal}/reject', [AffiliateOperationsAdminController::class, 'rejectWithdrawal'])->name('reject');
    Route::post('{withdrawal}/mark-paid', [AffiliateOperationsAdminController::class, 'markPaidWithdrawal'])->name('mark-paid');
});

// ── Dashboard & Health ──────────────────────────────────────────────────────
Route::middleware(['auth:api', 'active.session', 'role:admin,staff'])->group(function (): void {
    Route::get('dashboard', [AdminDashboardController::class, 'dashboard'])->name('admin.dashboard');
    Route::get('health', [AdminDashboardController::class, 'health'])->name('admin.health');

    // ── Reports ─────────────────────────────────────────────────────────────
    Route::prefix('reports')->name('admin.reports.')->group(function (): void {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::post('/', [ReportController::class, 'store'])->name('store');
        Route::get('{report}', [ReportController::class, 'show'])->name('show');
    });
});
