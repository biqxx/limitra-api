<?php

use App\Http\Controllers\Api\CartController;
use App\Http\Controllers\Api\CartItemController;
use App\Http\Controllers\Api\FavoriteController;
use Illuminate\Support\Facades\Route;

// ── Named cart actions ──────────────────────────────────────────────────────
// Must be declared BEFORE apiResource so they are not swallowed by {cart}
// implicit model binding.

// Get (or create) the active cart for a user.
Route::get('cart/active', [CartController::class, 'getActive'])->name('cart.active');

// Add a product to the user's active cart (auto-creates cart if absent).
Route::post('cart/add', [CartController::class, 'addItem'])->name('cart.add');

// Guest and authenticated line mutations resolve ownership from JWT or X-Cart-Token.
Route::patch('cart-items/{cartItem}', [CartItemController::class, 'update'])->name('cart-items.update');
Route::delete('cart-items/{cartItem}', [CartItemController::class, 'destroy'])->name('cart-items.destroy');

// Remove a specific item from a cart.
Route::delete('cart/{cart}/items/{cartItem}', [CartController::class, 'removeItem'])->name('cart.remove-item');

// Empty a cart without deleting it or checking it out.
Route::delete('cart/{cart}/clear', [CartController::class, 'clear'])->name('cart.clear');

// ── Standard CRUD ───────────────────────────────────────────────────────────
Route::get('shared-wishlists/{token}', [FavoriteController::class, 'shared'])->name('favorites.shared');

Route::middleware(['auth:api', 'active.session'])->group(function () {
    Route::post('cart/merge', [CartController::class, 'merge'])->name('cart.merge');
    Route::apiResource('cart', CartController::class);
    Route::apiResource('cart-items', CartItemController::class)->except(['update', 'destroy']);
    Route::post('favorites/share', [FavoriteController::class, 'share'])->name('favorites.share');
    Route::apiResource('favorites', FavoriteController::class);
});
