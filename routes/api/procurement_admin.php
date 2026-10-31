<?php

use App\Http\Controllers\Api\Admin\SupplierProcurementController;
use Illuminate\Support\Facades\Route;

// ── Admin Supplier Procurement & Order Fulfilment ───────────────────────────
Route::middleware(['auth:api', 'active.session', 'role:admin,staff'])
    ->prefix('procurement')
    ->name('admin.procurement.')
    ->group(function (): void {
        Route::get('orders', [SupplierProcurementController::class, 'index'])->name('orders.index');
        Route::get('orders/{order}', [SupplierProcurementController::class, 'show'])->name('orders.show');
        Route::post('orders', [SupplierProcurementController::class, 'store'])->name('orders.store');
        Route::patch('orders/{order}/status', [SupplierProcurementController::class, 'updateStatus'])->name('orders.status');
        Route::post('orders/{order}/sync', [SupplierProcurementController::class, 'sync'])->name('orders.sync');
    });
