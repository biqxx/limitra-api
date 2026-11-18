<?php

use App\Http\Controllers\Api\Microstore\MicrostoreOwnerController;
use App\Http\Controllers\Api\Microstore\MicrostorePublicController;
use Illuminate\Support\Facades\Route;

// ── Public Microstores ──────────────────────────────────────────────────────
Route::prefix('microstores')
    ->name('microstores.')
    ->group(function (): void {
        Route::get('/', [MicrostorePublicController::class, 'index'])->defaults('storeType', 'microstore')->name('index');
        Route::get('{slug}', [MicrostorePublicController::class, 'show'])->defaults('storeType', 'microstore')->name('show');
        Route::get('{slug}/products', [MicrostorePublicController::class, 'products'])->defaults('storeType', 'microstore')->name('products');
        Route::post('{slug}/clicks', [MicrostorePublicController::class, 'trackClick'])->defaults('storeType', 'microstore')->name('clicks');
    });

// ── Public Celebrity Stores ─────────────────────────────────────────────────
Route::prefix('celebrity-stores')
    ->name('celebrity-stores.')
    ->group(function (): void {
        Route::get('/', [MicrostorePublicController::class, 'index'])->defaults('storeType', 'celebrity')->name('index');
        Route::get('{slug}', [MicrostorePublicController::class, 'show'])->defaults('storeType', 'celebrity')->name('show');
        Route::get('{slug}/products', [MicrostorePublicController::class, 'products'])->defaults('storeType', 'celebrity')->name('products');
        Route::post('{slug}/clicks', [MicrostorePublicController::class, 'trackClick'])->defaults('storeType', 'celebrity')->name('clicks');
    });

// ── Creator / Owner Microstore Management ────────────────────────────────────
Route::middleware(['auth:api', 'active.session'])
    ->prefix('my-microstore')
    ->name('my-microstore.')
    ->group(function (): void {
        Route::get('/', [MicrostoreOwnerController::class, 'show'])->defaults('storeType', 'microstore')->name('show');
        Route::post('/', [MicrostoreOwnerController::class, 'store'])->defaults('storeType', 'microstore')->name('store');
        Route::patch('/', [MicrostoreOwnerController::class, 'update'])->defaults('storeType', 'microstore')->name('update');
        Route::post('products', [MicrostoreOwnerController::class, 'addProduct'])->defaults('storeType', 'microstore')->name('products.add');
        Route::patch('products/{product}', [MicrostoreOwnerController::class, 'updateProduct'])->defaults('storeType', 'microstore')->name('products.update');
        Route::delete('products/{product}', [MicrostoreOwnerController::class, 'removeProduct'])->defaults('storeType', 'microstore')->name('products.remove');
        Route::get('analytics', [MicrostoreOwnerController::class, 'analytics'])->defaults('storeType', 'microstore')->name('analytics');
    });

// ── Celebrity Store Owner Registration & Management ──────────────────────────
Route::middleware(['auth:api', 'active.session'])
    ->prefix('my-celebrity-store')
    ->name('my-celebrity-store.')
    ->group(function (): void {
        Route::get('/', [MicrostoreOwnerController::class, 'show'])->defaults('storeType', 'celebrity')->name('show');
        Route::post('/', [MicrostoreOwnerController::class, 'store'])->defaults('storeType', 'celebrity')->name('store');
        Route::patch('/', [MicrostoreOwnerController::class, 'update'])->defaults('storeType', 'celebrity')->name('update');
        Route::post('products', [MicrostoreOwnerController::class, 'addProduct'])->defaults('storeType', 'celebrity')->name('products.add');
        Route::patch('products/{product}', [MicrostoreOwnerController::class, 'updateProduct'])->defaults('storeType', 'celebrity')->name('products.update');
        Route::delete('products/{product}', [MicrostoreOwnerController::class, 'removeProduct'])->defaults('storeType', 'celebrity')->name('products.remove');
        Route::get('analytics', [MicrostoreOwnerController::class, 'analytics'])->defaults('storeType', 'celebrity')->name('analytics');
    });
