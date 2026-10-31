<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductImportController;
use Illuminate\Support\Facades\Route;

// ── Public (no auth required) ───────────────────────────────────────────────
// Browsing the catalogue is open; policy guards reject unauthorised writes
// inside the controllers themselves.
Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);
Route::get('categories/{category}/subcategories', [CategoryController::class, 'subcategories'])->name('categories.subcategories');

// Explicit discovery and static endpoints MUST precede dynamic /{product} lookup
Route::get('products/suggestions', [ProductController::class, 'suggestions'])->name('products.suggestions');
Route::get('products/import-template', [ProductImportController::class, 'template'])->name('products.import-template');
Route::get('products/{product}/related', [ProductController::class, 'related'])->name('products.related');
Route::get('products/{product}/alternatives', [ProductController::class, 'alternatives'])->name('products.alternatives');

Route::apiResource('products', ProductController::class)->only(['index', 'show']);

// ── Auth required for writes ─────────────────────────────────────────────────
// The ProductPolicy / CategoryPolicy before() allows only admins to write.
Route::middleware(['auth:api', 'active.session'])->group(function () {
    Route::post('products/imports', [ProductImportController::class, 'store'])->name('products.imports.store');
    Route::get('products/imports/{job}', [ProductImportController::class, 'show'])->name('products.imports.show');
    Route::post('products/imports/{job}/cancel', [ProductImportController::class, 'cancel'])->name('products.imports.cancel');
    Route::post('products/imports/{job}/retry', [ProductImportController::class, 'retry'])->name('products.imports.retry');
    Route::get('products/imports/{job}/errors', [ProductImportController::class, 'downloadErrors'])->name('products.imports.errors');
    Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
    Route::apiResource('products', ProductController::class)->except(['index', 'show']);
});
