<?php

use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\NotificationPreferenceController;
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

Route::middleware(['auth:api', 'active.session'])->group(function (): void {
    Route::get('notification-preferences', [NotificationPreferenceController::class, 'show'])
        ->name('notification-preferences.show');
    Route::put('notification-preferences', [NotificationPreferenceController::class, 'update'])
        ->name('notification-preferences.update');
});
