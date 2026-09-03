<?php

use App\Http\Controllers\Api\CustomerReferralController;
use Illuminate\Support\Facades\Route;

Route::post('referrals/resolve', [CustomerReferralController::class, 'resolve'])
    ->middleware('throttle:20,1')
    ->name('referrals.resolve');

Route::middleware(['auth:api', 'active.session'])->group(function (): void {
    Route::get('referrals/me', [CustomerReferralController::class, 'me'])->name('referrals.me');
    Route::post('referrals/share-events', [CustomerReferralController::class, 'shareEvent'])
        ->middleware('throttle:30,1')->name('referrals.share-events.store');
    Route::post('referrals/invitations', [CustomerReferralController::class, 'storeInvitation'])
        ->middleware('throttle:10,1')->name('referrals.invitations.store');
    Route::get('referrals/invitations', [CustomerReferralController::class, 'invitations'])
        ->name('referrals.invitations.index');
});
