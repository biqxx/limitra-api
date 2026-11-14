<?php

use App\Http\Controllers\Api\Admin\MediaController;
use App\Http\Controllers\Api\Content\BlogController;
use App\Http\Controllers\Api\Content\CareerController;
use App\Http\Controllers\Api\Content\ContentController;
use App\Http\Controllers\Api\Content\NewsletterController;
use App\Http\Controllers\Api\Content\VideoController;
use Illuminate\Support\Facades\Route;

// ── Admin Content, Media, Videos & Careers Management ─────────────────────────
Route::middleware(['auth:api', 'active.session'])->group(function (): void {
    // Content Entries, Blog & Newsletter (cms.read / cms.manage)
    Route::middleware('permission:cms.read')->group(function (): void {
        Route::get('content', [ContentController::class, 'index'])->name('admin.content.index');
        Route::get('content/{entry}', [ContentController::class, 'adminShow'])->name('admin.content.show');
        Route::get('content/{entry}/revisions', [ContentController::class, 'revisions'])->name('admin.content.revisions');
        Route::get('blog/posts', [BlogController::class, 'adminIndex'])->name('admin.blog.index');
        Route::get('blog/posts/{post}/revisions', [BlogController::class, 'revisions'])->name('admin.blog.revisions');
        Route::get('newsletter/subscribers', [NewsletterController::class, 'adminIndex'])->name('admin.newsletter.index');
    });

    Route::middleware('permission:cms.manage')->group(function (): void {
        Route::post('content', [ContentController::class, 'store'])->name('admin.content.store');
        Route::put('content/{entry}', [ContentController::class, 'update'])->name('admin.content.update');
        Route::post('content/{entry}/publish', [ContentController::class, 'publish'])->name('admin.content.publish');
        Route::post('content/{entry}/revisions/{revision}/restore', [ContentController::class, 'restoreRevision'])->name('admin.content.revisions.restore');
        Route::post('blog/posts', [BlogController::class, 'store'])->name('admin.blog.store');
        Route::patch('blog/posts/{post}', [BlogController::class, 'update'])->name('admin.blog.update');
        Route::post('blog/posts/{post}/publish', [BlogController::class, 'publish'])->name('admin.blog.publish');
        Route::delete('blog/posts/{post}', [BlogController::class, 'destroy'])->name('admin.blog.destroy');
        Route::post('blog/posts/{post}/revisions/{revision}/restore', [BlogController::class, 'restoreRevision'])->name('admin.blog.revisions.restore');
        Route::delete('newsletter/subscribers/{subscription}', [NewsletterController::class, 'destroy'])->name('admin.newsletter.destroy');
    });

    // Media Library (media.read / media.manage)
    Route::prefix('media')->name('admin.media.')->group(function (): void {
        Route::get('/', [MediaController::class, 'index'])->middleware('permission:media.read')->name('index');
        Route::get('{media}', [MediaController::class, 'show'])->middleware('permission:media.read')->name('show');
        Route::post('/', [MediaController::class, 'store'])->middleware('permission:media.manage')->name('store');
        Route::patch('{media}', [MediaController::class, 'update'])->middleware('permission:media.manage')->name('update');
        Route::delete('{media}', [MediaController::class, 'destroy'])->middleware('permission:media.manage')->name('destroy');
    });

    // Videos (videos.read / videos.manage)
    Route::get('videos', [VideoController::class, 'adminIndex'])->middleware('permission:videos.read')->name('admin.videos.index');
    Route::middleware('permission:videos.manage')->group(function (): void {
        Route::post('videos', [VideoController::class, 'store'])->name('admin.videos.store');
        Route::patch('videos/{video}', [VideoController::class, 'update'])->name('admin.videos.update');
        Route::delete('videos/{video}', [VideoController::class, 'destroy'])->name('admin.videos.destroy');
    });

    // Careers (careers.read / careers.manage)
    Route::middleware('permission:careers.read')->group(function (): void {
        Route::get('jobs', [CareerController::class, 'adminJobs'])->name('admin.jobs.index');
        Route::get('jobs/{job}', [CareerController::class, 'adminShowJob'])->name('admin.jobs.show');
        Route::get('job-applications', [CareerController::class, 'applications'])->name('admin.jobs.applications');
        Route::get('job-applications/{application}/cv', [CareerController::class, 'downloadCv'])->name('admin.jobs.applications.cv');
    });

    Route::middleware('permission:careers.manage')->group(function (): void {
        Route::post('jobs', [CareerController::class, 'storeJob'])->name('admin.jobs.store');
        Route::patch('jobs/{job}', [CareerController::class, 'updateJob'])->name('admin.jobs.update');
        Route::delete('jobs/{job}', [CareerController::class, 'destroyJob'])->name('admin.jobs.destroy');
        Route::patch('job-applications/{application}', [CareerController::class, 'updateApplicationStatus'])->name('admin.jobs.applications.update');
    });
});
