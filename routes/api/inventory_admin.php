<?php

use App\Http\Controllers\Api\Admin\InventoryController;
use Illuminate\Support\Facades\Route;

// ── Admin inventory management & immutable stock ledger ─────────────────────
Route::middleware(['auth:api', 'active.session', 'permission:inventory.read'])
    ->prefix('inventory')
    ->name('admin.inventory.')
    ->group(function (): void {
        Route::get('/', [InventoryController::class, 'index'])->name('index');
    });

Route::middleware(['auth:api', 'active.session', 'permission:inventory.adjust'])
    ->prefix('inventory')
    ->name('admin.inventory.')
    ->group(function (): void {
        Route::post('{product}/adjust', [InventoryController::class, 'adjust'])->name('adjust');
    });
