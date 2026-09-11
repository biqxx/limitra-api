<?php

use App\Http\Controllers\Api\Admin\BusinessSettingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'permission:settings.read'])->prefix('settings')->name('admin.settings.')->group(function () {
    Route::get('/', [BusinessSettingController::class, 'index'])->name('index');
    Route::get('{businessSetting}/history', [BusinessSettingController::class, 'history'])->name('history');
});

Route::middleware(['auth:api', 'active.session', 'permission:settings.manage'])
    ->patch('settings', [BusinessSettingController::class, 'update'])
    ->name('admin.settings.update');
