<?php

namespace App\Jobs;

use App\Exceptions\PaymentGatewayException;
use App\Models\Payment\Refund;
use App\Services\Notification\RefundNotificationService;
use App\Services\Payment\PaystackService;
use App\Services\Payment\RefundSettlementService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

class ProcessAutomaticRefund implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [30, 120, 300, 900];

    public function __construct(public readonly int $refundId)
    {
        $this->onQueue('payments');
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('automatic-refund:'.$this->refundId))
                ->releaseAfter(60)
                ->expireAfter(600)
                ->shared(),
        ];
    }

    public function handle(
        PaystackService $paystack,
        RefundSettlementService $settlement,
        BusinessSettingsService $settings,
    ): void {
        $refund = Refund::query()->whereKey($this->refundId)->where('source', 'late_payment')->firstOrFail();

        if (! in_array($refund->status, ['initiating', 'failed'], true)) {
            return;
        }

        if ($refund->status === 'failed') {
            $refund->update(['status' => 'initiating', 'failure_message' => null]);
        }

        try {
            $providerData = $paystack->createRefund([
                'transaction' => $refund->payment->reference,
                'amount' => $refund->amount_minor,
                'currency' => strtoupper($refund->currency),
                'customer_note' => $refund->reason,
                'merchant_note' => 'Automatic refund for late payment on order '.$refund->order->number.'.',
            ]);
            $settlement->apply($refund, $providerData);
        } catch (PaymentGatewayException $exception) {
            $refund->update([
                'status' => $exception->outcomeUnknown ? 'pending' : 'failed',
                'failure_message' => $exception->getMessage(),
                'next_reconciliation_at' => $exception->outcomeUnknown
                    ? now()->addMinutes((int) $settings->value('payments.refund_reconciliation_delay_minutes'))
                    : null,
            ]);

            if ($exception->outcomeUnknown) {
                report($exception);

                return;
            }

            throw $exception;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $refund = Refund::query()->find($this->refundId);
        if ($refund) {
            app(RefundNotificationService::class)->queueAttention($refund);
        }

        report($exception);
    }
}
