<?php

use App\Http\Controllers\Api\Admin\QueueMonitorController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'role:admin,staff'])
    ->prefix('queue')
    ->name('admin.queue.')
    ->group(function (): void {
        Route::get('status', [QueueMonitorController::class, 'status'])->name('status');
        Route::get('metrics', [QueueMonitorController::class, 'metrics'])->name('metrics');
        Route::get('failed-jobs', [QueueMonitorController::class, 'failed'])->name('failed');
    });

Route::middleware(['auth:api', 'role:admin'])
    ->prefix('queue')
    ->name('admin.queue.')
    ->group(function (): void {
        Route::post('failed-jobs/{id}/retry', [QueueMonitorController::class, 'retry'])
            ->where('id', '[A-Za-z0-9-]+')
            ->name('retry');
    });
