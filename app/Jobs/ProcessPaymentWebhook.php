<?php

namespace App\Jobs;

use App\Models\Payment\Payment;
use App\Models\Payment\PaymentWebhook;
use App\Services\Payment\PaymentSettlementService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 300];

    public function __construct(public readonly int $webhookId) {}

    public function handle(PaymentSettlementService $settlement): void
    {
        $webhook = PaymentWebhook::findOrFail($this->webhookId);
        if (in_array($webhook->status, ['processed', 'ignored'], true)) {
            return;
        }

        if ($webhook->event !== 'charge.success' || ! $webhook->reference) {
            $webhook->update(['status' => 'ignored', 'processed_at' => now(), 'error' => null]);

            return;
        }

        $payment = Payment::where('provider', $webhook->provider)
            ->where('reference', $webhook->reference)
            ->first();
        if (! $payment) {
            $webhook->update(['status' => 'ignored', 'processed_at' => now(), 'error' => 'Payment reference not found.']);

            return;
        }

        try {
            $settlement->apply($payment, $webhook->payload['data'] ?? []);
            $webhook->update(['status' => 'processed', 'processed_at' => now(), 'error' => null]);
        } catch (Throwable $exception) {
            $webhook->update(['status' => 'failed', 'error' => $exception->getMessage()]);

            throw $exception;
        }
    }
}
