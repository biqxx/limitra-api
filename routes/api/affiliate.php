<?php

use App\Http\Controllers\Api\Affiliate\AffiliateApplicationController;
use App\Http\Controllers\Api\Affiliate\AffiliatePortalOperationsController;
use App\Http\Controllers\Api\Affiliate\AffiliateTrackingController;
use App\Http\Controllers\Api\Affiliate\DashboardController;
use Illuminate\Support\Facades\Route;

// Public tracked referral link resolution
Route::get('l/{slug}', [AffiliateTrackingController::class, 'resolve'])->name('affiliate.track');

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
    Route::get('analytics', [DashboardController::class, 'analytics'])->name('analytics');
    Route::get('tier-history', [DashboardController::class, 'tierHistory'])->name('tier-history');

    // Custom referral links
    Route::get('links', [AffiliatePortalOperationsController::class, 'links'])->name('links.index');
    Route::post('links', [AffiliatePortalOperationsController::class, 'storeLink'])->name('links.store');
    Route::delete('links/{link}', [AffiliatePortalOperationsController::class, 'destroyLink'])->name('links.destroy');
    Route::get('links/{link}/analytics', [AffiliatePortalOperationsController::class, 'linkAnalytics'])->name('links.analytics');

    // Promo codes
    Route::get('promo-codes', [AffiliatePortalOperationsController::class, 'promoCodes'])->name('promo-codes.index');
    Route::post('promo-codes', [AffiliatePortalOperationsController::class, 'storePromoCode'])->name('promo-codes.store');
    Route::delete('promo-codes/{promoCode}', [AffiliatePortalOperationsController::class, 'destroyPromoCode'])->name('promo-codes.destroy');

    // Payout methods
    Route::get('payout-methods', [AffiliatePortalOperationsController::class, 'payoutMethods'])->name('payout-methods.index');
    Route::post('payout-methods', [AffiliatePortalOperationsController::class, 'storePayoutMethod'])->name('payout-methods.store');
    Route::post('payout-methods/verify', [AffiliatePortalOperationsController::class, 'verifyPayoutMethod'])->name('payout-methods.verify');
    Route::patch('payout-methods/{method}/default', [AffiliatePortalOperationsController::class, 'setDefaultPayoutMethod'])->name('payout-methods.default');
    Route::delete('payout-methods/{method}', [AffiliatePortalOperationsController::class, 'destroyPayoutMethod'])->name('payout-methods.destroy');

    // Withdrawals
    Route::get('withdrawals', [AffiliatePortalOperationsController::class, 'withdrawals'])->name('withdrawals.index');
    Route::post('withdrawals', [AffiliatePortalOperationsController::class, 'storeWithdrawal'])->name('withdrawals.store');

    // Ledger / Transactions
    Route::get('ledger', [AffiliatePortalOperationsController::class, 'ledger'])->name('ledger.index');

    // Promotable products
    Route::get('promotable-products', [AffiliatePortalOperationsController::class, 'promotableProducts'])->name('promotable-products');

    // Resources & downloads
    Route::get('resources', [AffiliatePortalOperationsController::class, 'resources'])->name('resources.index');
    Route::get('resources/{resource}/download', [AffiliatePortalOperationsController::class, 'downloadResource'])->name('resources.download');

    // Settings
    Route::get('settings', [AffiliatePortalOperationsController::class, 'settings'])->name('settings.show');
    Route::patch('settings', [AffiliatePortalOperationsController::class, 'updateSettings'])->name('settings.update');
});
