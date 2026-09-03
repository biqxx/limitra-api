<?php

use App\Http\Controllers\Api\RewardController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session'])->prefix('rewards')->name('rewards.')->group(function (): void {
    Route::get('/', [RewardController::class, 'index'])->name('index');
    Route::post('spin', [RewardController::class, 'spin'])->middleware('throttle:5,1')->name('spin');
    Route::post('{reward}/redeem', [RewardController::class, 'redeem'])
        ->middleware('throttle:10,1')->name('redeem');
});
