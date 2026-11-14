<?php

use App\Http\Controllers\Api\Admin\MicrostoreAdminController;
use Illuminate\Support\Facades\Route;

// ── Admin Microstores Management & Moderation ─────────────────────────────────
Route::middleware(['auth:api', 'active.session', 'role:admin,staff'])
    ->prefix('microstores')
    ->name('admin.microstores.')
    ->group(function (): void {
        Route::get('/', [MicrostoreAdminController::class, 'index'])->name('index');
        Route::post('/', [MicrostoreAdminController::class, 'store'])->name('store');
        Route::get('{microstore}', [MicrostoreAdminController::class, 'show'])->name('show');
        Route::patch('{microstore}', [MicrostoreAdminController::class, 'update'])->name('update');
        Route::post('{microstore}/moderate', [MicrostoreAdminController::class, 'moderate'])->name('moderate');
        Route::patch('{microstore}/feature', [MicrostoreAdminController::class, 'toggleFeatured'])->name('feature');
    });
