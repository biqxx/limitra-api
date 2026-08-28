<?php

namespace App\Services\Payment;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class PaystackService
{
    public function verifyTransaction(string $reference): array
    {
        $response = Http::baseUrl(config('services.paystack.base_url'))
            ->withToken(config('services.paystack.secret_key'))
            ->acceptJson()
            ->get('/transaction/verify/'.rawurlencode($reference));

        if (! $response->successful() || ! $response->json('status') || ! is_array($response->json('data'))) {
            throw new RuntimeException('Paystack could not verify this transaction.');
        }

        return $response->json('data');
    }
}
