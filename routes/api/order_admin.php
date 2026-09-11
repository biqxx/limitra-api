<?php

use App\Http\Controllers\Api\Admin\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'permission:orders.update'])
    ->patch('orders/{order}/status', [OrderController::class, 'updateStatus'])
    ->name('admin.orders.status');

Route::middleware(['auth:api', 'active.session', 'permission:orders.fulfill'])
    ->post('orders/{order}/ship', [OrderController::class, 'ship'])
    ->name('admin.orders.ship');
