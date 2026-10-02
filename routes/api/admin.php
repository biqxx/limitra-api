<?php

use App\Http\Controllers\Api\Admin\ProductController as AdminProductController;
use App\Http\Controllers\Api\ProductController as CatalogProductController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'role:admin'])
    ->prefix('products')
    ->name('admin.products.')
    ->group(function (): void {
        Route::get('/', [AdminProductController::class, 'index'])->name('index');
        Route::post('/', [CatalogProductController::class, 'store'])->name('store');
        Route::post('{product}/restore', [AdminProductController::class, 'restore'])
            ->withTrashed()
            ->name('restore');
        Route::get('{product}', [AdminProductController::class, 'show'])
            ->withTrashed()
            ->name('show');
        Route::match(['put', 'patch'], '{product}', [CatalogProductController::class, 'update'])->name('update');
        Route::delete('{product}', [CatalogProductController::class, 'destroy'])->name('destroy');
    });
