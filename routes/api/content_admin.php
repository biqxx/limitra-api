<?php

use App\Http\Controllers\Api\Content\BlogController;
use App\Http\Controllers\Api\Content\CareerController;
use App\Http\Controllers\Api\Content\ContentController;
use App\Http\Controllers\Api\Content\VideoController;
use Illuminate\Support\Facades\Route;

// ── Admin Content & CMS Management ──────────────────────────────────────────
Route::middleware(['auth:api', 'active.session', 'role:admin,staff'])->group(function (): void {
    // Content Entries
    Route::get('content', [ContentController::class, 'index'])->name('admin.content.index');
    Route::get('content/{entry}', [ContentController::class, 'adminShow'])->name('admin.content.show');
    Route::post('content', [ContentController::class, 'store'])->name('admin.content.store');
    Route::put('content/{entry}', [ContentController::class, 'update'])->name('admin.content.update');
    Route::post('content/{entry}/publish', [ContentController::class, 'publish'])->name('admin.content.publish');

    // Blog
    Route::get('blog/posts', [BlogController::class, 'adminIndex'])->name('admin.blog.index');
    Route::post('blog/posts', [BlogController::class, 'store'])->name('admin.blog.store');
    Route::patch('blog/posts/{post}', [BlogController::class, 'update'])->name('admin.blog.update');
    Route::post('blog/posts/{post}/publish', [BlogController::class, 'publish'])->name('admin.blog.publish');
    Route::delete('blog/posts/{post}', [BlogController::class, 'destroy'])->name('admin.blog.destroy');

    // Videos
    Route::get('videos', [VideoController::class, 'adminIndex'])->name('admin.videos.index');
    Route::post('videos', [VideoController::class, 'store'])->name('admin.videos.store');
    Route::patch('videos/{video}', [VideoController::class, 'update'])->name('admin.videos.update');
    Route::delete('videos/{video}', [VideoController::class, 'destroy'])->name('admin.videos.destroy');

    // Careers
    Route::get('jobs', [CareerController::class, 'adminJobs'])->name('admin.jobs.index');
    Route::post('jobs', [CareerController::class, 'storeJob'])->name('admin.jobs.store');
    Route::patch('jobs/{job}', [CareerController::class, 'updateJob'])->name('admin.jobs.update');
    Route::get('job-applications', [CareerController::class, 'applications'])->name('admin.jobs.applications');
    Route::patch('job-applications/{application}', [CareerController::class, 'updateApplicationStatus'])->name('admin.jobs.applications.update');
});
