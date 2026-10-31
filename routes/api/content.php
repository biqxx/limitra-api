<?php

use App\Http\Controllers\Api\Content\BlogController;
use App\Http\Controllers\Api\Content\CareerController;
use App\Http\Controllers\Api\Content\ContentController;
use App\Http\Controllers\Api\Content\NewsletterController;
use App\Http\Controllers\Api\Content\VideoController;
use Illuminate\Support\Facades\Route;

// ── Public Storefront Content ───────────────────────────────────────────────
Route::get('home', [ContentController::class, 'home'])->name('content.home');
Route::get('content/{page}', [ContentController::class, 'show'])->name('content.show');

// Blog
Route::prefix('blog')->name('blog.')->group(function (): void {
    Route::get('posts', [BlogController::class, 'index'])->name('index');
    Route::get('posts/{slug}', [BlogController::class, 'show'])->name('show');
});

// Videos
Route::prefix('videos')->name('videos.')->group(function (): void {
    Route::get('/', [VideoController::class, 'index'])->name('index');
    Route::post('events/batch', [VideoController::class, 'eventsBatch'])->name('events.batch');
    Route::get('{video}', [VideoController::class, 'show'])->name('show');
    Route::post('{video}/events', [VideoController::class, 'event'])->name('events');
});

// Newsletter
Route::prefix('newsletter')->name('newsletter.')->group(function (): void {
    Route::post('subscriptions', [NewsletterController::class, 'subscribe'])->name('subscribe');
    Route::post('unsubscribe', [NewsletterController::class, 'unsubscribe'])->name('unsubscribe');
    Route::get('unsubscribe/verify', [NewsletterController::class, 'verify'])->name('unsubscribe.verify');
});

// Careers
Route::prefix('jobs')->name('jobs.')->group(function (): void {
    Route::get('/', [CareerController::class, 'jobs'])->name('index');
    Route::post('open-applications', [CareerController::class, 'apply'])->name('open-applications');
    Route::get('{slug}', [CareerController::class, 'showJob'])->name('show');
    Route::post('{job}/applications', [CareerController::class, 'apply'])->name('apply');
});
