<?php

namespace App\Services\Payment;

use App\Exceptions\PaymentGatewayException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class PaystackService
{
    public function initializeTransaction(array $payload): array
    {
        return $this->post('/transaction/initialize', $payload, 'initialize this transaction');
    }

    public function chargeAuthorization(array $payload): array
    {
        return $this->post('/transaction/charge_authorization', $payload, 'charge this saved payment method');
    }

    public function verifyTransaction(string $reference): array
    {
        try {
            $response = Http::baseUrl(config('services.paystack.base_url'))
                ->withToken(config('services.paystack.secret_key'))
                ->acceptJson()
                ->timeout(15)
                ->get('/transaction/verify/'.rawurlencode($reference));
        } catch (ConnectionException $exception) {
            throw new PaymentGatewayException(
                'Paystack could not verify this transaction.',
                outcomeUnknown: true,
                previous: $exception,
            );
        }

        if (! $response->successful() || ! $response->json('status') || ! is_array($response->json('data'))) {
            throw new PaymentGatewayException(
                'Paystack could not verify this transaction.',
                outcomeUnknown: $response->serverError(),
            );
        }

        return $response->json('data');
    }

    public function hasValidWebhookSignature(string $payload, ?string $signature): bool
    {
        if (! $signature || ! config('services.paystack.secret_key')) {
            return false;
        }

        return hash_equals(
            hash_hmac('sha512', $payload, config('services.paystack.secret_key')),
            $signature,
        );
    }

    private function post(string $endpoint, array $payload, string $action): array
    {
        try {
            $response = Http::baseUrl(config('services.paystack.base_url'))
                ->withToken(config('services.paystack.secret_key'))
                ->acceptJson()
                ->asJson()
                ->timeout(15)
                ->post($endpoint, $payload);
        } catch (ConnectionException $exception) {
            throw new PaymentGatewayException(
                "Paystack could not {$action}.",
                outcomeUnknown: true,
                previous: $exception,
            );
        }

        if (! $response->successful() || ! $response->json('status') || ! is_array($response->json('data'))) {
            throw new PaymentGatewayException(
                "Paystack could not {$action}.",
                outcomeUnknown: $response->serverError(),
            );
        }

        return $response->json('data');
    }
}
