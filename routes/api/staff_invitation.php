<?php

use App\Http\Controllers\Api\StaffInvitationAcceptanceController;
use Illuminate\Support\Facades\Route;

Route::post('staff/invitations/accept', StaffInvitationAcceptanceController::class)
    ->middleware('throttle:5,1')
    ->name('staff-invitations.accept');
