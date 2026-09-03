<?php

use App\Http\Controllers\Api\CustomerReferralController;
use Illuminate\Support\Facades\Route;

Route::post('referrals/resolve', [CustomerReferralController::class, 'resolve'])
    ->middleware('throttle:20,1')
    ->name('referrals.resolve');

Route::middleware(['auth:api', 'active.session'])->group(function (): void {
    Route::get('referrals/me', [CustomerReferralController::class, 'me'])->name('referrals.me');
});
