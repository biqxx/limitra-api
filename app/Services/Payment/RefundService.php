<?php

namespace App\Services\Payment;

use App\Exceptions\PaymentGatewayException;
use App\Models\IdempotencyKey;
use App\Models\Order\ReturnRequest;
use App\Models\Payment\Payment;
use App\Models\Payment\Refund;
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
            $refund = DB::transaction(function () use ($returnRequest, $data, $actorId, $idempotencyKey, $operation, $requestHash, $amountMinor) {
                $existing = IdempotencyKey::where('user_id', $actorId)->where('operation', $operation)
                    ->where('key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    return $this->replay($existing, $requestHash)['refund'];
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

                $payment = Payment::where('order_id', $returnRequest->order_id)
                    ->where('status', 'succeeded')->latest('id')->lockForUpdate()->first();
                if (! $payment) {
                    $this->invalid('method', 'No successful online payment is available for an original-payment refund.');
                }
                $paymentCommittedMinor = (int) $payment->refunds()->whereNotIn('status', ['failed'])->sum('amount_minor');
                if ($amountMinor > $payment->amount_minor - $paymentCommittedMinor) {
                    $this->invalid('amount', 'The refund amount exceeds the remaining captured payment amount.');
                }

                $record = IdempotencyKey::create([
                    'user_id' => $actorId,
                    'operation' => $operation,
                    'key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'response_status' => 201,
                ]);
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

                return $refund;
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->idempotency($actorId, $operation, $idempotencyKey);
            if (! $existing) {
                throw $exception;
            }

            return $this->replay($existing, $requestHash);
        }

        if ($refund->status !== 'initiating') {
            return ['refund' => $refund, 'replayed' => true];
        }

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
