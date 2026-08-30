<?php

namespace App\Jobs;

use App\Models\Payment\Payment;
use App\Models\Payment\PaymentWebhook;
use App\Services\Payment\PaymentSettlementService;
use App\Services\Payment\RefundSettlementService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class ProcessPaymentWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 30, 120, 300];

    public function __construct(public readonly int $webhookId)
    {
        $this->onQueue('payments');
    }

    public function handle(PaymentSettlementService $settlement, RefundSettlementService $refunds): void
    {
        $webhook = PaymentWebhook::findOrFail($this->webhookId);
        if (in_array($webhook->status, ['processed', 'ignored'], true)) {
            return;
        }

        if (str_starts_with($webhook->event, 'refund.')) {
            $this->processRefund($webhook, $refunds);

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

    private function processRefund(PaymentWebhook $webhook, RefundSettlementService $refunds): void
    {
        try {
            $payload = $webhook->payload['data'] ?? [];
            $payload['status'] ??= str_replace('-', '_', substr($webhook->event, strlen('refund.')));
            $refund = $refunds->applyWebhook($webhook->provider, $payload);
            $webhook->update([
                'status' => $refund ? 'processed' : 'ignored',
                'processed_at' => now(),
                'error' => $refund ? null : 'Refund reference not found.',
            ]);
        } catch (Throwable $exception) {
            $webhook->update(['status' => 'failed', 'error' => $exception->getMessage()]);

            throw $exception;
        }
    }
}
