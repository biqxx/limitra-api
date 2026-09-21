<?php

use App\Http\Controllers\Api\AddressController;
use Illuminate\Support\Facades\Route;

Route::patch('addresses/{address}/set-default', [AddressController::class, 'setDefault'])
    ->name('addresses.set-default');

Route::apiResource('addresses', AddressController::class);
