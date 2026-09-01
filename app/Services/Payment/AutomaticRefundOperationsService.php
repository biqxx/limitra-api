<?php

namespace App\Services\Payment;

use App\Jobs\ProcessAutomaticRefund;
use App\Jobs\ReconcileAutomaticRefund;
use App\Models\IdempotencyKey;
use App\Models\Payment\Refund;
use App\Models\User;
use App\Services\Notification\RefundNotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AutomaticRefundOperationsService
{
    public function __construct(
        private readonly RefundSettlementService $settlement,
        private readonly RefundNotificationService $notifications,
    ) {}

    /** @return array{refund: Refund, replayed: bool, mode: string} */
    public function retry(Refund $refund, User $actor, string $idempotencyKey): array
    {
        $operation = "retry_auto_refund:{$refund->id}";
        $requestHash = $this->requestHash(['action' => 'retry', 'refund_id' => $refund->id]);
        $existing = $this->idempotency($actor->id, $operation, $idempotencyKey);

        if ($existing) {
            return [...$this->replay($existing, $requestHash), 'mode' => 'existing'];
        }

        try {
            $result = DB::transaction(function () use ($refund, $actor, $idempotencyKey, $operation, $requestHash): array {
                $existing = $this->lockedIdempotency($actor->id, $operation, $idempotencyKey);
                if ($existing) {
                    return [...$this->replay($existing, $requestHash), 'mode' => 'existing'];
                }

                $refund = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
                $this->assertAutomatic($refund);
                if (! in_array($refund->status, ['failed', 'needs_attention', 'pending', 'processing'], true)) {
                    $this->invalid('refund', "A {$refund->status} refund cannot be retried.");
                }

                $from = $refund->status;
                $mode = $refund->status === 'failed'
                    && ! $refund->provider_refund_id
                    && ! $refund->provider_reference
                        ? 'submission'
                        : 'reconciliation';
                $previousFailure = $refund->failure_message;

                $refund->update([
                    'status' => $mode === 'submission' ? 'initiating' : 'pending',
                    'failure_message' => null,
                    'next_reconciliation_at' => $mode === 'submission' ? null : now(),
                    'attention_notification_queued_at' => null,
                ]);

                $this->recordIdempotency($actor->id, $operation, $idempotencyKey, $requestHash, $refund, 202);
                $refund->events()->create([
                    'from_status' => $from,
                    'to_status' => $refund->status,
                    'action' => $mode === 'submission' ? 'submission_retry_requested' : 'reconciliation_requested',
                    'actor_id' => $actor->id,
                    'note' => 'Administrator requested another automatic refund attempt.',
                    'metadata' => array_filter([
                        'mode' => $mode,
                        'previous_failure' => $previousFailure,
                    ]),
                    'created_at' => now(),
                ]);

                return ['refund' => $refund->fresh(), 'replayed' => false, 'mode' => $mode];
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->idempotency($actor->id, $operation, $idempotencyKey);
            if (! $existing) {
                throw $exception;
            }

            return [...$this->replay($existing, $requestHash), 'mode' => 'existing'];
        }

        if (! $result['replayed']) {
            if ($result['mode'] === 'submission') {
                ProcessAutomaticRefund::dispatch($refund->id);
            } else {
                ReconcileAutomaticRefund::dispatch($refund->id);
            }
        }

        return $result;
    }

    /** @return array{refund: Refund, replayed: bool} */
    public function resolve(
        Refund $refund,
        array $data,
        User $actor,
        string $idempotencyKey,
    ): array {
        $operation = "resolve_auto_refund:{$refund->id}";
        $requestHash = $this->requestHash([
            'action' => 'resolve',
            'refund_id' => $refund->id,
            'status' => $data['status'],
            'note' => $data['note'],
            'provider_reference' => $data['provider_reference'] ?? null,
            'provider_refund_id' => $data['provider_refund_id'] ?? null,
            'processed_at' => $data['processed_at'] ?? null,
        ]);
        $existing = $this->idempotency($actor->id, $operation, $idempotencyKey);

        if ($existing) {
            return $this->replay($existing, $requestHash);
        }

        try {
            $result = DB::transaction(function () use ($refund, $data, $actor, $idempotencyKey, $operation, $requestHash): array {
                $existing = $this->lockedIdempotency($actor->id, $operation, $idempotencyKey);
                if ($existing) {
                    return $this->replay($existing, $requestHash);
                }

                $refund = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
                $this->assertAutomatic($refund);
                if (! in_array($refund->status, ['pending', 'processing', 'needs_attention', 'failed'], true)) {
                    $this->invalid('refund', "A {$refund->status} refund cannot be manually resolved.");
                }

                $from = $refund->status;
                if ($data['status'] === 'processed') {
                    $refund = $this->settlement->apply($refund, [
                        'id' => $data['provider_refund_id'] ?? $refund->provider_refund_id,
                        'refund_reference' => $data['provider_reference'],
                        'transaction_reference' => $refund->payment->reference,
                        'amount' => $refund->amount_minor,
                        'currency' => $refund->currency,
                        'status' => 'processed',
                        'refunded_at' => $data['processed_at'] ?? now()->toIso8601String(),
                    ]);
                    $refund->update(['processed_by' => $actor->id]);
                } else {
                    $refund->update([
                        'status' => 'failed',
                        'processed_by' => $actor->id,
                        'failure_message' => $data['note'],
                        'next_reconciliation_at' => null,
                    ]);
                }

                $this->recordIdempotency($actor->id, $operation, $idempotencyKey, $requestHash, $refund, 200);
                $refund->events()->create([
                    'from_status' => $from,
                    'to_status' => $data['status'],
                    'action' => $data['status'] === 'processed' ? 'manually_processed' : 'manually_failed',
                    'actor_id' => $actor->id,
                    'note' => $data['note'],
                    'metadata' => array_filter([
                        'provider_reference' => $data['provider_reference'] ?? null,
                        'provider_refund_id' => $data['provider_refund_id'] ?? null,
                        'processed_at' => $data['processed_at'] ?? null,
                    ]),
                    'created_at' => now(),
                ]);

                return ['refund' => $refund->fresh(), 'replayed' => false];
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->idempotency($actor->id, $operation, $idempotencyKey);
            if (! $existing) {
                throw $exception;
            }

            return $this->replay($existing, $requestHash);
        }

        if (! $result['replayed'] && $result['refund']->status === 'failed') {
            $this->notifications->queueAttention($result['refund']);
        }

        return $result;
    }

    private function assertAutomatic(Refund $refund): void
    {
        if ($refund->source !== 'late_payment') {
            $this->invalid('refund', 'Only automatic late-payment refunds can use this operation.');
        }
    }

    private function idempotency(int $actorId, string $operation, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('user_id', $actorId)
            ->where('operation', $operation)
            ->where('key', $key)
            ->first();
    }

    private function lockedIdempotency(int $actorId, string $operation, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::query()
            ->where('user_id', $actorId)
            ->where('operation', $operation)
            ->where('key', $key)
            ->lockForUpdate()
            ->first();
    }

    private function recordIdempotency(
        int $actorId,
        string $operation,
        string $key,
        string $requestHash,
        Refund $refund,
        int $responseStatus,
    ): void {
        IdempotencyKey::query()->create([
            'user_id' => $actorId,
            'operation' => $operation,
            'key' => $key,
            'request_hash' => $requestHash,
            'resource_type' => Refund::class,
            'resource_id' => $refund->id,
            'response_status' => $responseStatus,
        ]);
    }

    /** @return array{refund: Refund, replayed: bool} */
    private function replay(IdempotencyKey $record, string $requestHash): array
    {
        if (! hash_equals($record->request_hash, $requestHash)) {
            throw new ConflictHttpException('This idempotency key was already used with different refund details.');
        }
        if ($record->resource_type !== Refund::class || ! $record->resource_id) {
            throw new ConflictHttpException('A refund operation with this idempotency key is still being processed.');
        }

        return ['refund' => Refund::query()->findOrFail($record->resource_id), 'replayed' => true];
    }

    private function requestHash(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function invalid(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => [$message]]);
    }
}
