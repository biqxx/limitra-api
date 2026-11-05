<?php

use App\Http\Controllers\Api\Admin\ReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'permission:reviews.moderate'])->group(function () {
    Route::get('reviews', [ReviewController::class, 'index'])->name('admin.reviews.index');
    Route::get('reviews/{review}', [ReviewController::class, 'show'])->name('admin.reviews.show');
    Route::patch('reviews/{review}/moderate', [ReviewController::class, 'moderate'])->name('admin.reviews.moderate');
    Route::delete('reviews/{review}', [ReviewController::class, 'destroy'])->name('admin.reviews.destroy');
});
