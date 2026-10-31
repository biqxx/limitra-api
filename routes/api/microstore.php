<?php

use App\Http\Controllers\Api\Microstore\MicrostoreOwnerController;
use App\Http\Controllers\Api\Microstore\MicrostorePublicController;
use Illuminate\Support\Facades\Route;

// ── Public Microstores ──────────────────────────────────────────────────────
Route::prefix('microstores')->name('microstores.')->group(function (): void {
    Route::get('/', [MicrostorePublicController::class, 'index'])->name('index');
    Route::get('{slug}', [MicrostorePublicController::class, 'show'])->name('show');
    Route::get('{slug}/products', [MicrostorePublicController::class, 'products'])->name('products');
    Route::post('{slug}/clicks', [MicrostorePublicController::class, 'trackClick'])->name('clicks');
});

// ── Creator / Owner Microstore Management ────────────────────────────────────
Route::middleware(['auth:api', 'active.session'])->prefix('my-microstore')->name('my-microstore.')->group(function (): void {
    Route::get('/', [MicrostoreOwnerController::class, 'show'])->name('show');
    Route::post('/', [MicrostoreOwnerController::class, 'store'])->name('store');
    Route::patch('/', [MicrostoreOwnerController::class, 'update'])->name('update');
    Route::post('products', [MicrostoreOwnerController::class, 'addProduct'])->name('products.add');
    Route::patch('products/{product}', [MicrostoreOwnerController::class, 'updateProduct'])->name('products.update');
    Route::delete('products/{product}', [MicrostoreOwnerController::class, 'removeProduct'])->name('products.remove');
    Route::get('analytics', [MicrostoreOwnerController::class, 'analytics'])->name('analytics');
});
