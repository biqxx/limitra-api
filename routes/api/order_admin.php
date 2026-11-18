<?php

use App\Http\Controllers\Api\Admin\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'permission:orders.update'])->group(function (): void {
    Route::patch('orders/{order}', [OrderController::class, 'update'])->name('admin.orders.update');
    Route::delete('orders/{order}', [OrderController::class, 'destroy'])->name('admin.orders.destroy');
});

Route::middleware(['auth:api', 'active.session', 'permission:orders.update'])
    ->patch('orders/{order}/status', [OrderController::class, 'updateStatus'])
    ->name('admin.orders.status');

Route::middleware(['auth:api', 'active.session', 'permission:orders.fulfill'])
    ->group(function (): void {
        Route::post('orders/{order}/ship', [OrderController::class, 'ship'])->name('admin.orders.ship');
        Route::post('orders/{order}/ship/redstar', [OrderController::class, 'shipRedStar'])->name('admin.orders.ship.redstar');
        Route::get('orders/{order}/waybill', [OrderController::class, 'waybill'])->name('admin.orders.waybill');
        Route::post('orders/{order}/cancel-shipment', [OrderController::class, 'cancelShipment'])->name('admin.orders.cancel-shipment');
    });

Route::middleware(['auth:api', 'active.session', 'permission:refunds.resolve'])
    ->post('orders/{order}/refund', [OrderController::class, 'refund'])
    ->name('admin.orders.refund');
