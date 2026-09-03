<?php

use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('wallet', [WalletController::class, 'show'])->name('wallet.show');
Route::get('wallet/transactions', [WalletController::class, 'transactions'])->name('wallet.transactions.index');
