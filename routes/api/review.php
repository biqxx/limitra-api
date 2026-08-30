<?php

use App\Http\Controllers\Api\ProductReviewController;
use App\Http\Controllers\Api\ReviewController;
use Illuminate\Support\Facades\Route;

Route::get('products/{product}/reviews', [ProductReviewController::class, 'index'])
    ->name('products.reviews.index');

Route::middleware('auth:api')->group(function () {
    Route::post('products/{product}/reviews', [ProductReviewController::class, 'store'])
        ->name('products.reviews.store');
    Route::patch('reviews/{review}', [ReviewController::class, 'update'])->name('reviews.update');
    Route::delete('reviews/{review}', [ReviewController::class, 'destroy'])->name('reviews.destroy');
    Route::post('reviews/{review}/helpful', [ReviewController::class, 'helpful'])->name('reviews.helpful');
});
