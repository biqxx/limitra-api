<?php

use App\Http\Controllers\Api\Admin\ReturnController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'role:admin,staff'])->group(function () {
    Route::get('returns', [ReturnController::class, 'index'])->name('admin.returns.index');
    Route::patch('returns/{returnRequest}', [ReturnController::class, 'update'])->name('admin.returns.update');
});

Route::middleware(['auth:api', 'role:admin'])->post(
    'returns/{returnRequest}/refund',
    [ReturnController::class, 'refund'],
)->name('admin.returns.refund');
