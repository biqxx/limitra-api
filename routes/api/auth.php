<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProfileController;
use Illuminate\Support\Facades\Route;

// ── Public auth routes ──────────────────────────────────────────────────────
Route::post('signup', [AuthController::class, 'signup'])->name('auth.signup');
Route::post('verify-email', [AuthController::class, 'verifyEmail'])->name('auth.verify-email');
Route::post('resend-verification', [AuthController::class, 'resendVerification'])
    ->middleware('throttle:3,15')
    ->name('auth.resend-verification');
Route::post('login', [AuthController::class, 'login'])->name('auth.login');
Route::post('forgot-password', [AuthController::class, 'forgotPassword'])
    ->middleware('throttle:5,1')
    ->name('auth.forgot-password');
Route::post('reset-password', [AuthController::class, 'resetPassword'])->name('auth.reset-password');

// ── Protected auth routes ───────────────────────────────────────────────────
Route::middleware('auth:api')->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::post('refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
    Route::get('me', [AuthController::class, 'me'])->name('auth.me');

    // Profile management (own profile only)
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
});
