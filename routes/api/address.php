<?php

use App\Http\Controllers\Api\AddressController;
use Illuminate\Support\Facades\Route;

// ── Named address actions ───────────────────────────────────────────────────
// Promote an address to the default for its type (billing or delivery).
Route::patch('addresses/{address}/set-default', [AddressController::class, 'setDefault'])
    ->name('addresses.set-default');

// ── Standard CRUD ───────────────────────────────────────────────────────────
Route::apiResource('addresses', AddressController::class);
