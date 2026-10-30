<?php

use App\Http\Controllers\Api\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('wallet', [WalletController::class, 'show'])->name('wallet.show');
Route::get('wallet/transactions', [WalletController::class, 'transactions'])->name('wallet.transactions.index');
Route::post('wallet/deposits/initialize', [WalletController::class, 'initializeDeposit'])->name('wallet.deposits.initialize');
Route::post('wallet/deposits/{reference}/verify', [WalletController::class, 'verifyDeposit'])->name('wallet.deposits.verify');
