<?php

use App\Http\Controllers\Api\OrderController;
use Illuminate\Support\Facades\Route;

Route::apiResource('orders', OrderController::class)->only(['index', 'store', 'show']);
Route::post('orders/{order}/cancel', [OrderController::class, 'cancel'])->name('orders.cancel');
Route::patch('orders/{order}/delivery-address', [OrderController::class, 'updateDeliveryAddress'])->name('orders.delivery-address');
Route::post('orders/{order}/buy-again', [OrderController::class, 'buyAgain'])->name('orders.buy-again');
Route::get('orders/{order}/invoice', [OrderController::class, 'invoice'])->name('orders.invoice');
Route::get('orders/{order}/tracking', [OrderController::class, 'tracking'])->name('orders.tracking');
