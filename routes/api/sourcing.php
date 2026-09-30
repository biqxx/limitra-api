<?php

use App\Http\Controllers\Api\Admin\SourcingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'role:admin'])
    ->prefix('sourcing')
    ->name('admin.sourcing.')
    ->group(function () {
        Route::get('sources', [SourcingController::class, 'sources'])->name('sources');
        Route::get('search', [SourcingController::class, 'search'])->name('search');
        Route::get('imports', [SourcingController::class, 'index'])->name('imports.index');
        Route::post('imports', [SourcingController::class, 'store'])->name('imports.store');
    });
