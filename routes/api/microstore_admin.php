<?php

use App\Http\Controllers\Api\Admin\MicrostoreAdminController;
use Illuminate\Support\Facades\Route;

// ── Admin Microstores Management & Moderation ───────────────────────────────
Route::middleware(['auth:api', 'active.session', 'permission:microstores.read'])
    ->prefix('microstores')
    ->name('admin.microstores.')
    ->defaults('storeType', 'microstore')
    ->group(function (): void {
        Route::get('/', [MicrostoreAdminController::class, 'index'])->name('index');
        Route::get('{microstore}', [MicrostoreAdminController::class, 'show'])->name('show');
    });

Route::middleware(['auth:api', 'active.session', 'permission:microstores.manage'])
    ->prefix('microstores')
    ->name('admin.microstores.')
    ->defaults('storeType', 'microstore')
    ->group(function (): void {
        Route::post('/', [MicrostoreAdminController::class, 'store'])->name('store');
        Route::patch('{microstore}', [MicrostoreAdminController::class, 'update'])->name('update');
        Route::post('{microstore}/moderate', [MicrostoreAdminController::class, 'moderate'])->name('moderate');
        Route::patch('{microstore}/feature', [MicrostoreAdminController::class, 'toggleFeatured'])->name('feature');
    });

// ── Admin Celebrity Stores Management & Moderation ──────────────────────────
Route::middleware(['auth:api', 'active.session', 'permission:celebrity_stores.read'])
    ->prefix('celebrity-stores')
    ->name('admin.celebrity-stores.')
    ->defaults('storeType', 'celebrity')
    ->group(function (): void {
        Route::get('/', [MicrostoreAdminController::class, 'index'])->name('index');
        Route::get('{microstore}', [MicrostoreAdminController::class, 'show'])->name('show');
    });

Route::middleware(['auth:api', 'active.session', 'permission:celebrity_stores.manage'])
    ->prefix('celebrity-stores')
    ->name('admin.celebrity-stores.')
    ->defaults('storeType', 'celebrity')
    ->group(function (): void {
        Route::post('/', [MicrostoreAdminController::class, 'store'])->name('store');
        Route::patch('{microstore}', [MicrostoreAdminController::class, 'update'])->name('update');
        Route::post('{microstore}/moderate', [MicrostoreAdminController::class, 'moderate'])->name('moderate');
        Route::patch('{microstore}/feature', [MicrostoreAdminController::class, 'toggleFeatured'])->name('feature');
    });
