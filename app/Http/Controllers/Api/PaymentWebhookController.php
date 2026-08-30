<?php

namespace App\Http\Controllers\Api;

use App\Jobs\ProcessPaymentWebhook;
use App\Models\Payment\PaymentWebhook;
use App\Services\Payment\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentWebhookController extends BaseController
{
    public function handle(Request $request, string $provider, PaystackService $paystack): JsonResponse
    {
        if ($provider !== 'paystack') {
            return $this->error('Unsupported payment provider.', 404);
        }

        $rawPayload = $request->getContent();
        if (! $paystack->hasValidWebhookSignature($rawPayload, $request->header('x-paystack-signature'))) {
            return $this->error('Invalid webhook signature.', 401);
        }

        $payload = json_decode($rawPayload, true);
        if (! is_array($payload) || ! is_string($payload['event'] ?? null) || ! is_array($payload['data'] ?? null)) {
            return $this->error('Invalid webhook payload.', 400);
        }

        $webhook = PaymentWebhook::firstOrCreate([
            'payload_hash' => hash('sha256', $rawPayload),
        ], [
            'provider' => 'paystack',
            'event' => $payload['event'],
            'reference' => $payload['data']['reference'] ?? $payload['data']['transaction_reference'] ?? null,
            'payload' => $payload,
        ]);

        if ($webhook->wasRecentlyCreated) {
            ProcessPaymentWebhook::dispatch($webhook->id);
        }

        return $this->success(message: 'Webhook accepted.');
    }
}
