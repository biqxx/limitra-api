<?php

namespace App\Services\Payment;

use App\Exceptions\PaymentGatewayException;
use App\Models\Payment\Payment;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PaymentSettlementService
{
    public function apply(Payment $payment, array $transaction): Payment
    {
        return DB::transaction(function () use ($payment, $transaction) {
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $order = $payment->order()->lockForUpdate()->firstOrFail();

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
                $order->update([
                    'payment_status' => 'paid',
                    'status' => $order->status === 'pending_payment' ? 'confirmed' : $order->status,
                ]);
            } elseif (in_array($status, ['failed', 'abandoned'], true) && $order->payment_status !== 'paid') {
                $order->update(['payment_status' => 'failed']);
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
