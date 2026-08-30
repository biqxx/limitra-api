<?php

use App\Http\Controllers\Api\ReturnController;
use Illuminate\Support\Facades\Route;

Route::post('orders/{order}/returns', [ReturnController::class, 'store'])->name('returns.store');
Route::get('returns', [ReturnController::class, 'index'])->name('returns.index');
Route::get('returns/{returnRequest}', [ReturnController::class, 'show'])->name('returns.show');
Route::post('returns/{returnRequest}/cancel', [ReturnController::class, 'cancel'])->name('returns.cancel');
