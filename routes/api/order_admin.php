<?php

use App\Http\Controllers\Api\Admin\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'permission:orders.read'])->group(function (): void {
    Route::get('orders', [OrderController::class, 'index'])->name('admin.orders.index');
    Route::get('orders/{order}', [OrderController::class, 'show'])->name('admin.orders.show');
});

Route::middleware(['auth:api', 'active.session', 'permission:orders.update'])->group(function (): void {
    Route::patch('orders/{order}', [OrderController::class, 'update'])->name('admin.orders.update');
    Route::delete('orders/{order}', [OrderController::class, 'destroy'])->name('admin.orders.destroy');
});

Route::middleware(['auth:api', 'active.session', 'permission:orders.update'])
    ->patch('orders/{order}/status', [OrderController::class, 'updateStatus'])
    ->name('admin.orders.status');

Route::middleware(['auth:api', 'active.session', 'permission:orders.fulfill'])
    ->post('orders/{order}/ship', [OrderController::class, 'ship'])
    ->name('admin.orders.ship');
