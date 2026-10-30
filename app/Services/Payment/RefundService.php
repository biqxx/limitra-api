<?php

namespace App\Services\Payment;

use App\Exceptions\PaymentGatewayException;
use App\Models\IdempotencyKey;
use App\Models\Order\Order;
use App\Models\Order\ReturnRequest;
use App\Models\Payment\Payment;
use App\Models\Payment\Refund;
use App\Services\Notification\RefundNotificationService;
use App\Services\Referral\ReferralRewardService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class RefundService
{
    public function __construct(
        private readonly PaystackService $paystack,
        private readonly RefundSettlementService $settlement,
        private readonly WalletLedgerService $walletLedger,
        private readonly RefundNotificationService $notifications,
        private readonly ReferralRewardService $referralRewards,
    ) {}

    /** @return array{refund: Refund, replayed: bool} */
    public function create(ReturnRequest $returnRequest, array $data, int $actorId, string $idempotencyKey): array
    {
        $operation = "refund_return:{$returnRequest->id}";
        $amountMinor = $this->toMinorUnits($data['amount']);
        $requestHash = hash('sha256', json_encode([
            'return_id' => $returnRequest->id,
            'amount_minor' => $amountMinor,
            'method' => $data['method'],
            'reason' => $data['reason'],
        ], JSON_THROW_ON_ERROR));

        $existing = $this->idempotency($actorId, $operation, $idempotencyKey);
        if ($existing) {
            return $this->replay($existing, $requestHash);
        }

        try {
            $result = DB::transaction(function () use ($returnRequest, $data, $actorId, $idempotencyKey, $operation, $requestHash, $amountMinor) {
                $existing = IdempotencyKey::where('user_id', $actorId)->where('operation', $operation)
                    ->where('key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    return $this->replay($existing, $requestHash);
                }

                $returnRequest = ReturnRequest::whereKey($returnRequest->id)->lockForUpdate()->firstOrFail();
                if (! in_array($returnRequest->status, ['approved', 'received'], true)) {
                    $this->invalid('return', 'Only an approved or received return can be refunded.');
                }
                if ($returnRequest->resolution !== 'refund') {
                    $this->invalid('return', 'This return was not approved for a refund.');
                }

                $committedMinor = (int) $returnRequest->refunds()->whereNotIn('status', ['failed'])->sum('amount_minor');
                $approvedMinor = $this->toMinorUnits($returnRequest->approved_total);
                if ($amountMinor > $approvedMinor - $committedMinor) {
                    $this->invalid('amount', 'The refund amount exceeds the remaining approved amount.');
                }

                $order = Order::whereKey($returnRequest->order_id)->lockForUpdate()->firstOrFail();
                $payment = Payment::where('order_id', $returnRequest->order_id)
                    ->where('status', 'succeeded')->latest('id')->lockForUpdate()->first();

                $isWallet = ($data['method'] ?? '') === 'wallet'
                    || (! $payment && ((float) $order->credit_total > 0 || $order->payment_method === 'wallet'));

                if (! $isWallet && ! $payment) {
                    $this->invalid('method', 'No successful online payment is available for an original-payment refund.');
                }

                if (! $isWallet) {
                    $paymentCommittedMinor = (int) $payment->refunds()->whereNotIn('status', ['failed'])->sum('amount_minor');
                    if ($amountMinor > $payment->amount_minor - $paymentCommittedMinor) {
                        $this->invalid('amount', 'The refund amount exceeds the remaining captured payment amount.');
                    }
                }

                $record = IdempotencyKey::create([
                    'user_id' => $actorId,
                    'operation' => $operation,
                    'key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'response_status' => 201,
                ]);

                if ($isWallet) {
                    $refund = Refund::create([
                        'return_request_id' => $returnRequest->id,
                        'order_id' => $returnRequest->order_id,
                        'payment_id' => $payment?->id,
                        'user_id' => $returnRequest->user_id,
                        'processed_by' => $actorId,
                        'reference' => 'LMT-REF-'.Str::upper(Str::random(20)),
                        'provider' => 'wallet',
                        'method' => 'wallet',
                        'source' => 'return',
                        'status' => 'processed',
                        'currency' => $returnRequest->currency,
                        'amount' => $this->fromMinorUnits($amountMinor),
                        'amount_minor' => $amountMinor,
                        'reason' => $data['reason'],
                        'processed_at' => now(),
                    ]);
                    $record->update(['resource_type' => Refund::class, 'resource_id' => $refund->id]);

                    $this->walletLedger->credit(
                        user: $returnRequest->user,
                        amountMinor: $amountMinor,
                        balanceType: 'cash',
                        type: 'refund',
                        uniqueKey: "return_refund:{$refund->id}",
                        description: "Refund for return {$returnRequest->number}.",
                        sourceType: 'refund',
                        sourceId: $refund->id,
                        metadata: [
                            'return_request_id' => $returnRequest->id,
                            'order_id' => $order->id,
                            'refund_reference' => $refund->reference,
                            'reason' => $data['reason'],
                        ],
                    );

                    $orderRefundedMinor = (int) $order->refunds()->where('status', 'processed')->sum('amount_minor');
                    $orderTotalMinor = $this->toMinorUnits($order->grand_total) + $this->toMinorUnits($order->credit_total);
                    if ($orderTotalMinor === 0) {
                        $orderTotalMinor = $this->toMinorUnits($order->total_amount);
                    }
                    $order->update([
                        'payment_status' => $orderRefundedMinor >= $orderTotalMinor
                            ? 'refunded'
                            : 'partially_refunded',
                    ]);
                    if ($order->payment_status === 'refunded') {
                        $this->referralRewards->reverseForFullyRefundedOrder($order);
                    }

                    $returnRefundedMinor = (int) $returnRequest->refunds()->where('status', 'processed')->sum('amount_minor');
                    if ($returnRequest->status === 'received' && $returnRefundedMinor >= $approvedMinor) {
                        $returnRequest->update(['status' => 'completed', 'completed_at' => now()]);
                        $returnRequest->events()->create([
                            'from_status' => 'received',
                            'to_status' => 'completed',
                            'source' => 'payment',
                            'note' => 'Approved refund processed to wallet.',
                            'metadata' => ['refund_id' => $refund->id],
                            'created_at' => now(),
                        ]);
                    }

                    $this->notifications->queueProcessed($refund);

                    return ['refund' => $refund, 'replayed' => false];
                }

                $refund = Refund::create([
                    'return_request_id' => $returnRequest->id,
                    'order_id' => $returnRequest->order_id,
                    'payment_id' => $payment->id,
                    'user_id' => $returnRequest->user_id,
                    'processed_by' => $actorId,
                    'reference' => 'LMT-REF-'.Str::upper(Str::random(20)),
                    'provider' => $payment->provider,
                    'method' => $data['method'],
                    'source' => 'return',
                    'status' => 'initiating',
                    'currency' => $returnRequest->currency,
                    'amount' => $this->fromMinorUnits($amountMinor),
                    'amount_minor' => $amountMinor,
                    'reason' => $data['reason'],
                ]);
                $record->update(['resource_type' => Refund::class, 'resource_id' => $refund->id]);

                return ['refund' => $refund, 'replayed' => false];
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->idempotency($actorId, $operation, $idempotencyKey);
            if (! $existing) {
                throw $exception;
            }

            return $this->replay($existing, $requestHash);
        }

        if ($result['replayed'] || $result['refund']->status !== 'initiating') {
            return $result;
        }

        $refund = $result['refund'];

        try {
            $providerData = $this->paystack->createRefund([
                'transaction' => $refund->payment->reference,
                'amount' => $refund->amount_minor,
                'currency' => strtoupper($refund->currency),
                'customer_note' => $refund->reason,
                'merchant_note' => "Return {$refund->returnRequest->number}: {$refund->reason}",
            ]);
            $refund = $this->settlement->apply($refund, $providerData);
        } catch (PaymentGatewayException $exception) {
            $refund->update([
                'status' => $exception->outcomeUnknown ? 'pending' : 'failed',
                'failure_message' => $exception->getMessage(),
            ]);
            throw $exception;
        }

        return ['refund' => $refund, 'replayed' => false];
    }

    /** @return array{refund: Refund, replayed: bool} */
    public function createForOrder(Order $order, array $data, int $actorId, string $idempotencyKey): array
    {
        $operation = "refund_order:{$order->id}";
        $amountMinor = $this->toMinorUnits($data['amount']);
        $requestHash = hash('sha256', json_encode([
            'order_id' => $order->id,
            'amount_minor' => $amountMinor,
            'method' => $data['method'] ?? 'original_payment',
            'reason' => $data['reason'],
        ], JSON_THROW_ON_ERROR));

        $existing = $this->idempotency($actorId, $operation, $idempotencyKey);
        if ($existing) {
            return $this->replay($existing, $requestHash);
        }

        try {
            $result = DB::transaction(function () use ($order, $data, $actorId, $idempotencyKey, $operation, $requestHash, $amountMinor) {
                $existing = IdempotencyKey::where('user_id', $actorId)->where('operation', $operation)
                    ->where('key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    return $this->replay($existing, $requestHash);
                }

                $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
                if (! in_array($order->payment_status, ['paid', 'partially_refunded'], true)) {
                    $this->invalid('order', 'Only paid or partially refunded orders can be refunded.');
                }

                $payment = Payment::where('order_id', $order->id)
                    ->where('status', 'succeeded')
                    ->latest('id')
                    ->lockForUpdate()
                    ->first();

                $isWallet = ($data['method'] ?? '') === 'wallet'
                    || (! $payment && ((float) $order->credit_total > 0 || $order->payment_method === 'wallet'));

                if (! $isWallet && ! $payment) {
                    $this->invalid('method', 'No successful online payment is available for an original-payment refund.');
                }

                $committedMinor = (int) $order->refunds()->whereNotIn('status', ['failed'])->sum('amount_minor');
                $orderTotalMinor = $this->toMinorUnits($order->grand_total) + $this->toMinorUnits($order->credit_total);
                if ($orderTotalMinor === 0) {
                    $orderTotalMinor = $this->toMinorUnits($order->total_amount);
                }

                $capturedMinor = $isWallet ? $orderTotalMinor : $payment->amount_minor;
                if ($amountMinor > $capturedMinor - $committedMinor) {
                    $this->invalid('amount', 'The refund amount exceeds the remaining captured payment amount.');
                }

                $record = IdempotencyKey::create([
                    'user_id' => $actorId,
                    'operation' => $operation,
                    'key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'response_status' => 201,
                ]);

                if ($isWallet) {
                    $refund = Refund::create([
                        'return_request_id' => null,
                        'order_id' => $order->id,
                        'payment_id' => $payment?->id,
                        'user_id' => $order->user_id,
                        'processed_by' => $actorId,
                        'reference' => 'LMT-REF-'.Str::upper(Str::random(20)),
                        'provider' => 'wallet',
                        'method' => 'wallet',
                        'source' => 'admin_order',
                        'status' => 'processed',
                        'currency' => $order->currency,
                        'amount' => $this->fromMinorUnits($amountMinor),
                        'amount_minor' => $amountMinor,
                        'reason' => $data['reason'],
                        'processed_at' => now(),
                    ]);
                    $record->update(['resource_type' => Refund::class, 'resource_id' => $refund->id]);

                    $this->walletLedger->credit(
                        user: $order->user,
                        amountMinor: $amountMinor,
                        balanceType: 'cash',
                        type: 'refund',
                        uniqueKey: "order_refund:{$refund->id}",
                        description: "Refund for order {$order->number}.",
                        sourceType: 'refund',
                        sourceId: $refund->id,
                        metadata: [
                            'order_id' => $order->id,
                            'refund_reference' => $refund->reference,
                            'reason' => $data['reason'],
                        ],
                    );

                    $orderRefundedMinor = (int) $order->refunds()->where('status', 'processed')->sum('amount_minor');
                    $order->update([
                        'payment_status' => $orderRefundedMinor >= $orderTotalMinor
                            ? 'refunded'
                            : 'partially_refunded',
                    ]);
                    if ($order->payment_status === 'refunded') {
                        $this->referralRewards->reverseForFullyRefundedOrder($order);
                    }

                    $this->notifications->queueProcessed($refund);

                    return ['refund' => $refund, 'replayed' => false];
                }

                $refund = Refund::create([
                    'return_request_id' => null,
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'user_id' => $order->user_id,
                    'processed_by' => $actorId,
                    'reference' => 'LMT-REF-'.Str::upper(Str::random(20)),
                    'provider' => $payment->provider,
                    'method' => $data['method'] ?? 'original_payment',
                    'source' => 'admin_order',
                    'status' => 'initiating',
                    'currency' => $order->currency,
                    'amount' => $this->fromMinorUnits($amountMinor),
                    'amount_minor' => $amountMinor,
                    'reason' => $data['reason'],
                ]);
                $record->update(['resource_type' => Refund::class, 'resource_id' => $refund->id]);

                return ['refund' => $refund, 'replayed' => false];
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->idempotency($actorId, $operation, $idempotencyKey);
            if (! $existing) {
                throw $exception;
            }

            return $this->replay($existing, $requestHash);
        }

        if ($result['replayed'] || $result['refund']->status !== 'initiating') {
            return $result;
        }

        $refund = $result['refund'];

        try {
            $providerData = $this->paystack->createRefund([
                'transaction' => $refund->payment->reference,
                'amount' => $refund->amount_minor,
                'currency' => strtoupper($refund->currency),
                'customer_note' => $refund->reason,
                'merchant_note' => "Order {$order->number}: {$refund->reason}",
            ]);
            $refund = $this->settlement->apply($refund, $providerData);
        } catch (PaymentGatewayException $exception) {
            $refund->update([
                'status' => $exception->outcomeUnknown ? 'pending' : 'failed',
                'failure_message' => $exception->getMessage(),
            ]);
            throw $exception;
        }

        return ['refund' => $refund, 'replayed' => false];
    }

    private function idempotency(int $userId, string $operation, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::where('user_id', $userId)->where('operation', $operation)->where('key', $key)->first();
    }

    /** @return array{refund: Refund, replayed: bool} */
    private function replay(IdempotencyKey $record, string $requestHash): array
    {
        if (! hash_equals($record->request_hash, $requestHash)) {
            throw new ConflictHttpException('This idempotency key was already used with different refund details.');
        }
        if ($record->resource_type !== Refund::class || ! $record->resource_id) {
            throw new ConflictHttpException('A refund request with this idempotency key is still being processed.');
        }

        return ['refund' => Refund::findOrFail($record->resource_id), 'replayed' => true];
    }

    private function toMinorUnits(mixed $amount): int
    {
        [$whole, $fraction] = explode('.', number_format((float) $amount, 2, '.', ''));

        return ((int) $whole * 100) + (int) $fraction;
    }

    private function fromMinorUnits(int $amount): string
    {
        return number_format($amount / 100, 2, '.', '');
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}
