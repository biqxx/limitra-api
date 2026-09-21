<?php

use App\Http\Controllers\Api\Admin\RewardCampaignController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'permission:rewards.read'])
    ->prefix('reward-campaigns')
    ->name('admin.reward-campaigns.')
    ->group(function (): void {
        Route::get('/', [RewardCampaignController::class, 'index'])->name('index');
        Route::get('{campaign}/wins', [RewardCampaignController::class, 'wins'])->name('wins');
    });

Route::middleware(['auth:api', 'active.session', 'permission:rewards.manage'])
    ->prefix('reward-campaigns')
    ->name('admin.reward-campaigns.')
    ->group(function (): void {
        Route::post('/', [RewardCampaignController::class, 'store'])->name('store');
        Route::patch('{campaign}', [RewardCampaignController::class, 'update'])->name('update');
    });
