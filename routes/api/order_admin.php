<?php

use App\Http\Controllers\Api\Admin\OrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'role:admin'])->prefix('orders')->name('admin.orders.')->group(function () {
    Route::patch('{order}/status', [OrderController::class, 'updateStatus'])->name('status');
    Route::post('{order}/ship', [OrderController::class, 'ship'])->name('ship');
});
