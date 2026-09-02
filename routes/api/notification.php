<?php

use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session'])
    ->prefix('notifications')
    ->name('notifications.')
    ->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::patch('{notification}/read', [NotificationController::class, 'read'])
            ->whereUuid('notification')
            ->name('read');
        Route::post('read-all', [NotificationController::class, 'readAll'])->name('read-all');
    });
