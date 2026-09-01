<?php

use App\Http\Controllers\Api\Admin\RefundController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'role:admin,staff'])
    ->prefix('refunds')
    ->name('admin.refunds.')
    ->group(function (): void {
        Route::get('/', [RefundController::class, 'index'])->name('index');
        Route::get('{refund}', [RefundController::class, 'show'])->name('show');
    });

Route::middleware(['auth:api', 'role:admin'])
    ->prefix('refunds')
    ->name('admin.refunds.')
    ->group(function (): void {
        Route::post('{refund}/retry', [RefundController::class, 'retry'])->name('retry');
        Route::post('{refund}/resolve', [RefundController::class, 'resolve'])->name('resolve');
    });
