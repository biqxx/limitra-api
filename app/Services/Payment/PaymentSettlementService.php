<?php

namespace App\Services\Payment;

use App\Exceptions\PaymentGatewayException;
use App\Models\Payment\Payment;
use App\Services\Order\InventoryReservationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentSettlementService
{
    public function __construct(
        private readonly InventoryReservationService $reservations,
        private readonly LatePaymentRefundService $latePaymentRefunds,
    ) {}

    public function apply(Payment $payment, array $transaction): Payment
    {
        return DB::transaction(function () use ($payment, $transaction) {
            $order = $payment->order()->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();

            $this->assertTransactionMatches($payment, $transaction);
            $providerStatus = strtolower((string) ($transaction['status'] ?? 'pending'));
            $status = match ($providerStatus) {
                'success' => 'succeeded',
                'failed' => 'failed',
                'abandoned' => 'abandoned',
                default => 'pending',
            };

            $payment->update([
                'status' => $status,
                'provider_transaction_id' => isset($transaction['id']) ? (string) $transaction['id'] : $payment->provider_transaction_id,
                'channel' => $transaction['channel'] ?? $payment->channel,
                'gateway_response' => $transaction['gateway_response'] ?? $payment->gateway_response,
                'failure_message' => in_array($status, ['failed', 'abandoned'], true)
                    ? ($transaction['message'] ?? $transaction['gateway_response'] ?? 'Payment was not completed.')
                    : null,
                'provider_metadata' => array_filter([
                    'fees' => $transaction['fees'] ?? null,
                    'domain' => $transaction['domain'] ?? null,
                ], fn ($value) => $value !== null),
                'paid_at' => $status === 'succeeded'
                    ? (isset($transaction['paid_at']) ? Carbon::parse($transaction['paid_at']) : now())
                    : null,
                'verified_at' => now(),
            ]);

            if ($status === 'succeeded' && $order->payment_status !== 'paid') {
                $fromStatus = $order->status;
                $restored = $order->cancellation_code === InventoryReservationService::EXPIRY_CANCELLATION_CODE
                    ? $this->reservations->restoreForLatePayment($order)
                    : false;
                $attributes = [
                    'payment_status' => 'paid',
                    'status' => $order->status === 'pending_payment' ? 'confirmed' : $order->status,
                ];

                if ($restored) {
                    $attributes['status'] = 'confirmed';
                    $attributes['fulfilment_status'] = 'unfulfilled';
                    $attributes['cancelled_at'] = null;
                    $attributes['cancellation_code'] = null;
                    $attributes['cancellation_reason'] = null;
                } elseif ($order->cancellation_code === InventoryReservationService::EXPIRY_CANCELLATION_CODE) {
                    $attributes['cancellation_code'] = InventoryReservationService::LATE_PAYMENT_CANCELLATION_CODE;
                    $attributes['cancellation_reason'] = 'Payment succeeded after the reservation expired, but inventory is unavailable. Refund required.';
                }

                $order->update($attributes);
                $this->reservations->removeExpiry($order);

                if ($fromStatus !== $order->status || $order->cancellation_code === InventoryReservationService::LATE_PAYMENT_CANCELLATION_CODE) {
                    $order->statusEvents()->create([
                        'from_status' => $fromStatus,
                        'to_status' => $order->status,
                        'note' => $order->cancellation_code === InventoryReservationService::LATE_PAYMENT_CANCELLATION_CODE
                            ? 'Late payment confirmed, but inventory could not be reserved. Refund required.'
                            : ($restored ? 'Late payment confirmed and inventory reserved again.' : 'Payment confirmed.'),
                        'source' => 'payment',
                    ]);
                }
            } elseif (in_array($status, ['failed', 'abandoned'], true) && $order->payment_status !== 'paid') {
                $order->update(['payment_status' => 'failed']);
            }

            if ($status === 'succeeded'
                && $order->cancellation_code === InventoryReservationService::LATE_PAYMENT_CANCELLATION_CODE) {
                $this->latePaymentRefunds->ensure($payment);
            }

            return $payment->fresh();
        }, 3);
    }

    private function assertTransactionMatches(Payment $payment, array $transaction): void
    {
        if (($transaction['reference'] ?? null) !== $payment->reference) {
            throw new PaymentGatewayException('The provider reference does not match this payment.');
        }
        if ((int) ($transaction['amount'] ?? -1) !== $payment->amount_minor) {
            throw new PaymentGatewayException('The provider amount does not match this payment.');
        }
        if (strtoupper((string) ($transaction['currency'] ?? '')) !== strtoupper($payment->currency)) {
            throw new PaymentGatewayException('The provider currency does not match this payment.');
        }

        $providerEmail = $transaction['customer']['email'] ?? null;
        if ($providerEmail && strtolower($providerEmail) !== strtolower($payment->customer_email)) {
            throw new PaymentGatewayException('The provider customer does not match this payment.');
        }
    }
}
