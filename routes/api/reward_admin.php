<?php

use App\Http\Controllers\Api\Admin\RewardCampaignController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:api', 'active.session', 'role:admin'])
    ->prefix('reward-campaigns')
    ->name('admin.reward-campaigns.')
    ->group(function (): void {
        Route::get('/', [RewardCampaignController::class, 'index'])->name('index');
        Route::post('/', [RewardCampaignController::class, 'store'])->name('store');
        Route::patch('{campaign}', [RewardCampaignController::class, 'update'])->name('update');
        Route::get('{campaign}/wins', [RewardCampaignController::class, 'wins'])->name('wins');
    });
