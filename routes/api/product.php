<?php

use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\ProductController;
use Illuminate\Support\Facades\Route;

// ── Public (no auth required) ───────────────────────────────────────────────
// Browsing the catalogue is open; policy guards reject unauthorised writes
// inside the controllers themselves.
Route::apiResource('categories', CategoryController::class)->only(['index', 'show']);
Route::get('categories/{category}/subcategories', [CategoryController::class, 'subcategories'])->name('categories.subcategories');
Route::apiResource('products', ProductController::class)->only(['index', 'show']);

// ── Auth required for writes ─────────────────────────────────────────────────
// The ProductPolicy / CategoryPolicy before() allows only admins to write.
Route::middleware('auth:api')->group(function () {
    Route::apiResource('categories', CategoryController::class)->except(['index', 'show']);
    Route::apiResource('products', ProductController::class)->except(['index', 'show']);
});
