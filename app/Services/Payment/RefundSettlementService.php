<?php

namespace App\Services\Payment;

use App\Exceptions\PaymentGatewayException;
use App\Models\Payment\Refund;
use App\Services\Notification\RefundNotificationService;
use App\Services\Order\InventoryReservationService;
use App\Services\Settings\BusinessSettingsService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class RefundSettlementService
{
    public function __construct(
        private readonly RefundNotificationService $notifications,
        private readonly BusinessSettingsService $settings,
    ) {}

    public function apply(Refund $refund, array $providerData): Refund
    {
        return DB::transaction(function () use ($refund, $providerData) {
            $refund = Refund::whereKey($refund->id)->lockForUpdate()->firstOrFail();
            $returnRequest = $refund->return_request_id
                ? $refund->returnRequest()->lockForUpdate()->firstOrFail()
                : null;
            $order = $refund->order()->lockForUpdate()->firstOrFail();
            $this->assertMatches($refund, $providerData);

            if ($refund->status === 'processed') {
                return $refund;
            }

            $providerStatus = strtolower((string) ($providerData['status'] ?? 'pending'));
            $status = match ($providerStatus) {
                'processed' => 'processed',
                'processing' => 'processing',
                'needs-attention', 'needs_attention' => 'needs_attention',
                'failed' => 'failed',
                default => 'pending',
            };
            $transaction = $providerData['transaction'] ?? null;
            $providerReference = $providerData['refund_reference'] ?? $providerData['reference'] ?? null;
            $nextReconciliationAt = null;
            if ($refund->source === 'late_payment' && in_array($status, ['pending', 'processing'], true)) {
                $setting = $refund->reconciliation_attempts === 0
                    ? 'payments.refund_reconciliation_delay_minutes'
                    : 'payments.refund_reconciliation_interval_minutes';
                $nextReconciliationAt = now()->addMinutes((int) $this->settings->value($setting));
            }

            $refund->update([
                'status' => $status,
                'provider_refund_id' => isset($providerData['id']) ? (string) $providerData['id'] : $refund->provider_refund_id,
                'provider_reference' => $providerReference ?: $refund->provider_reference,
                'failure_message' => in_array($status, ['failed', 'needs_attention'], true)
                    ? ($providerData['reason'] ?? $providerData['message'] ?? 'The refund could not be processed.')
                    : null,
                'provider_metadata' => array_filter([
                    'transaction_reference' => is_array($transaction) ? ($transaction['reference'] ?? null) : ($providerData['transaction_reference'] ?? null),
                    'expected_at' => $providerData['expected_at'] ?? null,
                    'channel' => $providerData['channel'] ?? null,
                    'fully_deducted' => $providerData['fully_deducted'] ?? null,
                ], fn ($value) => $value !== null),
                'processed_at' => $status === 'processed'
                    ? (isset($providerData['refunded_at']) ? Carbon::parse($providerData['refunded_at']) : now())
                    : null,
                'next_reconciliation_at' => $nextReconciliationAt,
            ]);

            if ($status === 'processed') {
                $orderRefundedMinor = (int) $order->refunds()->where('status', 'processed')->sum('amount_minor');
                $order->update([
                    'payment_status' => $orderRefundedMinor >= $this->toMinorUnits($order->grand_total)
                        ? 'refunded'
                        : 'partially_refunded',
                ]);

                $returnRefundedMinor = $returnRequest
                    ? (int) $returnRequest->refunds()->where('status', 'processed')->sum('amount_minor')
                    : 0;
                if ($returnRequest?->status === 'received'
                    && $returnRefundedMinor >= $this->toMinorUnits($returnRequest->approved_total)) {
                    $returnRequest->update(['status' => 'completed', 'completed_at' => now()]);
                    $returnRequest->events()->create([
                        'from_status' => 'received',
                        'to_status' => 'completed',
                        'source' => 'payment',
                        'note' => 'Approved refund processed.',
                        'metadata' => ['refund_id' => $refund->id],
                        'created_at' => now(),
                    ]);
                }

                if ($refund->source === 'late_payment') {
                    $order->update([
                        'cancellation_code' => InventoryReservationService::LATE_PAYMENT_REFUNDED_CANCELLATION_CODE,
                        'cancellation_reason' => 'Payment succeeded after inventory expired and was automatically refunded.',
                    ]);
                    $order->statusEvents()->create([
                        'from_status' => $order->status,
                        'to_status' => $order->status,
                        'source' => 'payment',
                        'note' => 'Late payment was automatically refunded.',
                    ]);

                    $this->notifications->queueProcessed($refund);
                }
            } elseif ($refund->source === 'late_payment' && in_array($status, ['failed', 'needs_attention'], true)) {
                $this->notifications->queueAttention($refund);
            }

            return $refund->fresh();
        }, 3);
    }

    public function applyWebhook(string $provider, array $providerData): ?Refund
    {
        $transactionReference = $providerData['transaction_reference']
            ?? (is_array($providerData['transaction'] ?? null) ? ($providerData['transaction']['reference'] ?? null) : null);
        if (! $transactionReference) {
            return null;
        }

        $query = Refund::query()
            ->where('provider', $provider)
            ->whereHas('payment', fn ($payment) => $payment->where('reference', $transactionReference));

        $refund = null;
        if (! empty($providerData['refund_reference'])) {
            $refund = (clone $query)->where('provider_reference', $providerData['refund_reference'])->first();
        }
        if (! $refund && ! empty($providerData['id'])) {
            $refund = (clone $query)->where('provider_refund_id', (string) $providerData['id'])->first();
        }
        if (! $refund) {
            $refund = $query->whereIn('status', ['initiating', 'pending', 'processing', 'needs_attention'])
                ->when(isset($providerData['amount']), fn ($refunds) => $refunds->where('amount_minor', (int) $providerData['amount']))
                ->oldest('id')
                ->first();
        }

        return $refund ? $this->apply($refund, $providerData) : null;
    }

    private function assertMatches(Refund $refund, array $providerData): void
    {
        $transaction = $providerData['transaction'] ?? null;
        $transactionReference = $providerData['transaction_reference']
            ?? (is_array($transaction) ? ($transaction['reference'] ?? null) : null);
        if ($transactionReference && $transactionReference !== $refund->payment->reference) {
            throw new PaymentGatewayException('The provider transaction does not match this refund.');
        }
        if (isset($providerData['amount']) && (int) $providerData['amount'] !== $refund->amount_minor) {
            throw new PaymentGatewayException('The provider amount does not match this refund.');
        }
        if (isset($providerData['currency'])
            && strtoupper((string) $providerData['currency']) !== strtoupper($refund->currency)) {
            throw new PaymentGatewayException('The provider currency does not match this refund.');
        }
    }

    private function toMinorUnits(mixed $amount): int
    {
        [$whole, $fraction] = explode('.', number_format((float) $amount, 2, '.', ''));

        return ((int) $whole * 100) + (int) $fraction;
    }
}
