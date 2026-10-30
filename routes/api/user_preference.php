<?php

use App\Http\Controllers\Api\UserPreferenceController;
use Illuminate\Support\Facades\Route;

// ── Customer user preferences singleton ────────────────────────────────────
Route::get('user-preferences', [UserPreferenceController::class, 'show'])
    ->name('user-preferences.show');

Route::match(['put', 'patch'], 'user-preferences', [UserPreferenceController::class, 'update'])
    ->name('user-preferences.update');
