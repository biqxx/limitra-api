<?php

namespace Tests\Feature\Payment;

use App\Jobs\ProcessPaymentWebhook;
use App\Models\Payment\PaymentWebhook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentWebhookRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_retry_redispatches_job_when_status_is_failed(): void
    {
        Queue::fake();

        $secret = 'sk_test_mock_secret_key';
        config(['services.paystack.secret_key' => $secret]);

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'id' => 998877,
                'status' => 'success',
                'reference' => 'PAY-REF-123456',
                'amount' => 500000,
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha512', $payload, $secret);
        $payloadHash = hash('sha256', $payload);

        // Pre-create the webhook record in 'failed' status as if a previous attempt failed
        $webhook = PaymentWebhook::create([
            'provider' => 'paystack',
            'event' => 'charge.success',
            'reference' => 'PAY-REF-123456',
            'payload_hash' => $payloadHash,
            'payload' => json_decode($payload, true),
            'status' => 'failed',
            'error' => 'Simulated queue or connection failure',
        ]);

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/payments/paystack',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
            ],
            $payload
        );

        $response->assertOk();
        $this->assertDatabaseCount('payment_webhooks', 1);

        // Job must be re-dispatched for the failed webhook
        Queue::assertPushed(ProcessPaymentWebhook::class, function ($job) use ($webhook) {
            return $job->webhookId === $webhook->id;
        });
    }

    public function test_webhook_retry_does_not_redispatch_when_status_is_processed(): void
    {
        Queue::fake();

        $secret = 'sk_test_mock_secret_key';
        config(['services.paystack.secret_key' => $secret]);

        $payload = json_encode([
            'event' => 'charge.success',
            'data' => [
                'id' => 998878,
                'status' => 'success',
                'reference' => 'PAY-REF-PROCESSED',
                'amount' => 500000,
            ],
        ], JSON_THROW_ON_ERROR);

        $signature = hash_hmac('sha512', $payload, $secret);
        $payloadHash = hash('sha256', $payload);

        $webhook = PaymentWebhook::create([
            'provider' => 'paystack',
            'event' => 'charge.success',
            'reference' => 'PAY-REF-PROCESSED',
            'payload_hash' => $payloadHash,
            'payload' => json_decode($payload, true),
            'status' => 'processed',
        ]);

        $response = $this->call(
            'POST',
            '/api/v1/webhooks/payments/paystack',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_PAYSTACK_SIGNATURE' => $signature,
            ],
            $payload
        );

        $response->assertOk();
        Queue::assertNotPushed(ProcessPaymentWebhook::class);
    }
}
