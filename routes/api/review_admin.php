<?php

use App\Http\Controllers\Api\Admin\ReviewController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'role:admin,staff'])->group(function () {
    Route::patch('reviews/{review}/moderate', [ReviewController::class, 'moderate'])
        ->name('admin.reviews.moderate');
});
