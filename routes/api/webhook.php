<?php

use App\Http\Controllers\Api\PaymentWebhookController;
use App\Http\Controllers\Api\WebhookController;
use App\Http\Controllers\Api\XWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('webhooks/payments/{provider}', [PaymentWebhookController::class, 'handle'])
    ->name('webhooks.payments');

// Meta (WhatsApp / Instagram / Facebook) — HMAC-SHA256 via app secret.
Route::get('webhook/meta', [WebhookController::class, 'verify'])->name('webhook.meta.verify');
Route::post('webhook/meta', [WebhookController::class, 'receive'])->name('webhook.meta.receive');

// X (Twitter) — CRC challenge + HMAC-SHA256 via consumer secret.
Route::get('webhook/x', [XWebhookController::class, 'verify'])->name('webhook.x.verify');
Route::post('webhook/x', [XWebhookController::class, 'receive'])->name('webhook.x.receive');
