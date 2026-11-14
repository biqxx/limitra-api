<?php

use App\Http\Controllers\Api\Admin\MicrostoreAdminController;
use Illuminate\Support\Facades\Route;

// ── Admin Microstores & Celebrity Stores Management & Moderation ──────────────
Route::middleware(['auth:api', 'active.session', 'permission:microstores.read,celebrity_stores.read'])
    ->prefix('microstores')
    ->name('admin.microstores.')
    ->group(function (): void {
        Route::get('/', [MicrostoreAdminController::class, 'index'])->name('index');
        Route::get('{microstore}', [MicrostoreAdminController::class, 'show'])->name('show');
    });

Route::middleware(['auth:api', 'active.session', 'permission:microstores.manage,celebrity_stores.manage'])
    ->prefix('microstores')
    ->name('admin.microstores.')
    ->group(function (): void {
        Route::post('/', [MicrostoreAdminController::class, 'store'])->name('store');
        Route::patch('{microstore}', [MicrostoreAdminController::class, 'update'])->name('update');
        Route::post('{microstore}/moderate', [MicrostoreAdminController::class, 'moderate'])->name('moderate');
        Route::patch('{microstore}/feature', [MicrostoreAdminController::class, 'toggleFeatured'])->name('feature');
    });
