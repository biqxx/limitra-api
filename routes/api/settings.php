<?php

use App\Http\Controllers\Api\Admin\BusinessSettingController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'role:admin'])->prefix('settings')->name('admin.settings.')->group(function () {
    Route::get('/', [BusinessSettingController::class, 'index'])->name('index');
    Route::patch('/', [BusinessSettingController::class, 'update'])->name('update');
    Route::get('{businessSetting}/history', [BusinessSettingController::class, 'history'])->name('history');
});
