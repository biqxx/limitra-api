<?php

use App\Http\Controllers\Api\Affiliate\AffiliateApplicationController;
use App\Http\Controllers\Api\Affiliate\AffiliatePortalOperationsController;
use App\Http\Controllers\Api\Affiliate\DashboardController;
use Illuminate\Support\Facades\Route;

// Customer routes for affiliate onboarding
Route::middleware(['auth:api', 'active.session'])->prefix('affiliate')->name('affiliate.')->group(function (): void {
    Route::post('applications', [AffiliateApplicationController::class, 'apply'])->name('applications.apply');
    Route::get('application', [AffiliateApplicationController::class, 'status'])->name('applications.status');
});

// Portal routes requiring valid JWT + affiliate role
Route::middleware(['auth:api', 'active.session', 'role:affiliate'])->prefix('affiliate')->name('affiliate.')->group(function (): void {
    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('referrals', [DashboardController::class, 'referrals'])->name('referrals');
    Route::get('sales', [DashboardController::class, 'sales'])->name('sales');
    Route::get('commissions', [DashboardController::class, 'commissions'])->name('commissions');

    // Custom referral links
    Route::get('links', [AffiliatePortalOperationsController::class, 'links'])->name('links.index');
    Route::post('links', [AffiliatePortalOperationsController::class, 'storeLink'])->name('links.store');
    Route::delete('links/{link}', [AffiliatePortalOperationsController::class, 'destroyLink'])->name('links.destroy');

    // Promo codes
    Route::get('promo-codes', [AffiliatePortalOperationsController::class, 'promoCodes'])->name('promo-codes.index');
    Route::post('promo-codes', [AffiliatePortalOperationsController::class, 'storePromoCode'])->name('promo-codes.store');
    Route::delete('promo-codes/{promoCode}', [AffiliatePortalOperationsController::class, 'destroyPromoCode'])->name('promo-codes.destroy');

    // Payout methods
    Route::get('payout-methods', [AffiliatePortalOperationsController::class, 'payoutMethods'])->name('payout-methods.index');
    Route::post('payout-methods', [AffiliatePortalOperationsController::class, 'storePayoutMethod'])->name('payout-methods.store');
    Route::patch('payout-methods/{method}/default', [AffiliatePortalOperationsController::class, 'setDefaultPayoutMethod'])->name('payout-methods.default');
    Route::delete('payout-methods/{method}', [AffiliatePortalOperationsController::class, 'destroyPayoutMethod'])->name('payout-methods.destroy');

    // Withdrawals
    Route::get('withdrawals', [AffiliatePortalOperationsController::class, 'withdrawals'])->name('withdrawals.index');
    Route::post('withdrawals', [AffiliatePortalOperationsController::class, 'storeWithdrawal'])->name('withdrawals.store');
});
