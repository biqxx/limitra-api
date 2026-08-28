<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\SavedCardController;
use Illuminate\Support\Facades\Route;

Route::post('payments/initialize', [PaymentController::class, 'initialize'])->name('payments.initialize');
Route::get('payments/reference/{reference}/status', [PaymentController::class, 'status'])->name('payments.status');
Route::post('payments/{payment}/retry', [PaymentController::class, 'retry'])->name('payments.retry');
Route::get('payments/{payment}', [PaymentController::class, 'show'])->name('payments.show');

// ── Named saved-card actions ────────────────────────────────────────────────
// Set a card as the user's default payment method.
Route::patch('saved-cards/{savedCard}/set-default', [SavedCardController::class, 'setDefault'])
    ->name('saved-cards.set-default');

// ── Standard CRUD ───────────────────────────────────────────────────────────
Route::apiResource('saved-cards', SavedCardController::class);

// ── Wallet / Account ────────────────────────────────────────────────────────
Route::apiResource('accounts', AccountController::class);
Route::post('accounts/{account}/deposit', [AccountController::class, 'deposit'])->name('accounts.deposit');
Route::post('accounts/{account}/withdraw', [AccountController::class, 'withdraw'])->name('accounts.withdraw');
