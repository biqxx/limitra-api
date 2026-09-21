<?php

use App\Http\Controllers\Api\Admin\ReturnController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'permission:returns.read'])->group(function () {
    Route::get('returns', [ReturnController::class, 'index'])->name('admin.returns.index');
});

Route::middleware(['auth:api', 'active.session', 'permission:returns.manage'])
    ->patch('returns/{returnRequest}', [ReturnController::class, 'update'])
    ->name('admin.returns.update');

Route::middleware(['auth:api', 'active.session', 'permission:returns.refund'])->post(
    'returns/{returnRequest}/refund',
    [ReturnController::class, 'refund'],
)->name('admin.returns.refund');
