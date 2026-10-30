<?php

use App\Http\Controllers\Api\PublicSettingController;
use Illuminate\Support\Facades\Route;

// ── Public storefront settings ──────────────────────────────────────────────
Route::get('settings/public', [PublicSettingController::class, 'index'])
    ->name('settings.public');
