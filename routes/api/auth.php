<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CustomerAccountController;
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
Route::middleware(['auth:api', 'active.session'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('auth.logout');
    Route::post('refresh', [AuthController::class, 'refresh'])->name('auth.refresh');
    Route::get('me', [AuthController::class, 'me'])->name('auth.me');

    // Profile management (own profile only)
    Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::post('profile/avatar', [ProfileController::class, 'uploadAvatar'])->name('profile.avatar.store');
    Route::delete('profile/avatar', [ProfileController::class, 'deleteAvatar'])->name('profile.avatar.destroy');
    Route::post('profile/email/verify', [ProfileController::class, 'verifyEmailChange'])->name('profile.email.verify');

    Route::get('account/dashboard', [CustomerAccountController::class, 'dashboard'])->name('account.dashboard');
    Route::post('account/password/change', [CustomerAccountController::class, 'changePassword'])->name('account.password.change');
    Route::get('account/sessions', [CustomerAccountController::class, 'sessions'])->name('account.sessions.index');
    Route::delete('account/sessions/{session}', [CustomerAccountController::class, 'revokeSession'])->name('account.sessions.destroy');
});
