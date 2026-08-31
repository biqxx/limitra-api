<?php

namespace App\Services\Payment;

use App\Jobs\ProcessAutomaticRefund;
use App\Models\Payment\Payment;
use App\Models\Payment\Refund;
use Illuminate\Support\Str;

class LatePaymentRefundService
{
    public function ensure(Payment $payment): Refund
    {
        $refund = Refund::query()->firstOrCreate(
            ['automation_key' => 'late_payment:'.$payment->id],
            [
                'return_request_id' => null,
                'order_id' => $payment->order_id,
                'payment_id' => $payment->id,
                'user_id' => $payment->user_id,
                'processed_by' => null,
                'reference' => 'LMT-AUTO-REF-'.Str::upper(Str::random(20)),
                'provider' => $payment->provider,
                'method' => 'original_payment',
                'source' => 'late_payment',
                'status' => 'initiating',
                'currency' => $payment->currency,
                'amount' => $payment->amount,
                'amount_minor' => $payment->amount_minor,
                'reason' => 'Automatic refund because inventory was unavailable after a late payment.',
            ],
        );

        if ($refund->status === 'initiating') {
            ProcessAutomaticRefund::dispatch($refund->id)->afterCommit();
        }

        return $refund;
    }
}
