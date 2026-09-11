<?php

use App\Http\Controllers\Api\Admin\NotificationSettingController;
use App\Http\Controllers\Api\NotificationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'permission:notifications.read'])
    ->prefix('notifications')
    ->name('admin.notifications.')
    ->group(function (): void {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('unread-count', [NotificationController::class, 'unreadCount'])->name('unread-count');
        Route::patch('{notification}/read', [NotificationController::class, 'read'])
            ->whereUuid('notification')
            ->name('read');
        Route::post('read-all', [NotificationController::class, 'readAll'])->name('read-all');
    });

Route::middleware(['auth:api', 'active.session', 'permission:notifications.manage'])
    ->prefix('notification-settings')
    ->name('admin.notification-settings.')
    ->group(function (): void {
        Route::get('/', [NotificationSettingController::class, 'index'])->name('index');
    });

Route::middleware(['auth:api', 'active.session', 'permission:notifications.manage'])
    ->patch('notification-settings/{event}', [NotificationSettingController::class, 'update'])
    ->where('event', '[a-z0-9._-]+')
    ->name('admin.notification-settings.update');
