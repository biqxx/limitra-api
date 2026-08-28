<?php

use App\Http\Controllers\Api\Admin\CommerceRuleController;
use App\Http\Controllers\Api\CheckoutController;
use App\Http\Controllers\Api\DeliveryController;
use App\Http\Controllers\Api\PromotionController;
use Illuminate\Support\Facades\Route;

Route::middleware('active.session')->group(function () {
    Route::get('delivery/options', [DeliveryController::class, 'options'])->name('delivery.options');
    Route::get('pickup-locations', [DeliveryController::class, 'pickupLocations'])->name('pickup-locations.index');
    Route::post('promotions/validate', [PromotionController::class, 'validateCode'])->name('promotions.validate');
});

Route::middleware(['auth:api', 'active.session', 'role:admin'])->prefix('admin')->group(function () {
    Route::get('delivery-zones', [CommerceRuleController::class, 'zones']);
    Route::post('delivery-zones', [CommerceRuleController::class, 'storeZone']);
    Route::patch('delivery-zones/{zone}', [CommerceRuleController::class, 'updateZone']);
    Route::get('delivery-methods', [CommerceRuleController::class, 'methods']);
    Route::post('delivery-methods', [CommerceRuleController::class, 'storeMethod']);
    Route::patch('delivery-methods/{method}', [CommerceRuleController::class, 'updateMethod']);
    Route::put('delivery-zones/{zone}/methods/{method}', [CommerceRuleController::class, 'setZoneMethod']);
    Route::get('pickup-locations', [CommerceRuleController::class, 'pickups']);
    Route::post('pickup-locations', [CommerceRuleController::class, 'storePickup']);
    Route::patch('pickup-locations/{pickup}', [CommerceRuleController::class, 'updatePickup']);
    Route::get('promotions', [CommerceRuleController::class, 'promotions']);
    Route::post('promotions', [CommerceRuleController::class, 'storePromotion']);
    Route::patch('promotions/{promotion}', [CommerceRuleController::class, 'updatePromotion']);
});

Route::middleware(['auth:api', 'active.session'])->group(function () {
    Route::post('checkout/quote', [CheckoutController::class, 'quote'])->name('checkout.quote');
});
