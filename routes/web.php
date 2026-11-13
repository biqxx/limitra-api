<?php

use App\Http\Controllers\Api\Affiliate\AffiliateTrackingController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return view('welcome');
});

Route::get('l/{slug}', [AffiliateTrackingController::class, 'resolve'])->name('web.affiliate.track');

Route::get('media/{path}', function (string $path) {
    if (str_contains($path, '..') || ! Storage::disk('public')->exists($path)) {
        abort(404);
    }

    return Storage::disk('public')->response($path, null, [
        'Cache-Control' => 'public, max-age=31536000, immutable',
        'Access-Control-Allow-Origin' => '*',
    ]);
})->where('path', '.*')->name('media.public');
