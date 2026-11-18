<?php

use App\Http\Controllers\Api\Admin\MicrostoreAdminController;
use Illuminate\Support\Facades\Route;

// ── Admin Microstores Management & Moderation ───────────────────────────────
Route::middleware(['auth:api', 'active.session', 'permission:microstores.read'])
    ->prefix('microstores')
    ->name('admin.microstores.')
    ->group(function (): void {
        Route::get('/', [MicrostoreAdminController::class, 'index'])->defaults('storeType', 'microstore')->name('index');
        Route::get('{microstore}', [MicrostoreAdminController::class, 'show'])->defaults('storeType', 'microstore')->name('show');
    });

Route::middleware(['auth:api', 'active.session', 'permission:microstores.manage'])
    ->prefix('microstores')
    ->name('admin.microstores.')
    ->group(function (): void {
        Route::post('/', [MicrostoreAdminController::class, 'store'])->defaults('storeType', 'microstore')->name('store');
        Route::patch('{microstore}', [MicrostoreAdminController::class, 'update'])->defaults('storeType', 'microstore')->name('update');
        Route::post('{microstore}/moderate', [MicrostoreAdminController::class, 'moderate'])->defaults('storeType', 'microstore')->name('moderate');
        Route::patch('{microstore}/feature', [MicrostoreAdminController::class, 'toggleFeatured'])->defaults('storeType', 'microstore')->name('feature');
    });

// ── Admin Celebrity Stores Management & Moderation ──────────────────────────
Route::middleware(['auth:api', 'active.session', 'permission:celebrity_stores.read'])
    ->prefix('celebrity-stores')
    ->name('admin.celebrity-stores.')
    ->group(function (): void {
        Route::get('/', [MicrostoreAdminController::class, 'index'])->defaults('storeType', 'celebrity')->name('index');
        Route::get('{microstore}', [MicrostoreAdminController::class, 'show'])->defaults('storeType', 'celebrity')->name('show');
    });

Route::middleware(['auth:api', 'active.session', 'permission:celebrity_stores.manage'])
    ->prefix('celebrity-stores')
    ->name('admin.celebrity-stores.')
    ->group(function (): void {
        Route::post('/', [MicrostoreAdminController::class, 'store'])->defaults('storeType', 'celebrity')->name('store');
        Route::patch('{microstore}', [MicrostoreAdminController::class, 'update'])->defaults('storeType', 'celebrity')->name('update');
        Route::post('{microstore}/moderate', [MicrostoreAdminController::class, 'moderate'])->defaults('storeType', 'celebrity')->name('moderate');
        Route::patch('{microstore}/feature', [MicrostoreAdminController::class, 'toggleFeatured'])->defaults('storeType', 'celebrity')->name('feature');
    });
