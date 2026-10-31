<?php

use App\Http\Controllers\Api\Affiliate\AffiliateTrackingController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('l/{slug}', [AffiliateTrackingController::class, 'resolve'])->name('web.affiliate.track');
