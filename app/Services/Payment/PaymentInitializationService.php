<?php

namespace App\Services\Payment;

use App\Exceptions\PaymentGatewayException;
use App\Models\IdempotencyKey;
use App\Models\Order\Order;
use App\Models\Payment\Payment;
use App\Models\Payment\SavedCard;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class PaymentInitializationService
{
    public function __construct(
        private readonly PaystackService $paystack,
        private readonly PaymentSettlementService $settlement,
    ) {}

    /**
     * @return array{payment: Payment, replayed: bool}
     */
    public function initialize(
        int $orderId,
        array $data,
        int $userId,
        string $idempotencyKey,
        ?Payment $parent = null,
    ): array {
        $operation = $parent ? "retry_payment:{$parent->id}" : 'initialize_payment';
        $requestHash = $this->requestHash($orderId, $data, $parent);
        $existing = $this->findIdempotencyKey($userId, $operation, $idempotencyKey);
        if ($existing) {
            return $this->replay($existing, $requestHash);
        }

        try {
            $payment = DB::transaction(function () use ($orderId, $data, $userId, $idempotencyKey, $operation, $requestHash, $parent) {
                $existing = IdempotencyKey::where('user_id', $userId)->where('operation', $operation)
                    ->where('key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    return $this->replay($existing, $requestHash)['payment'];
                }

                $lockedParent = $parent
                    ? Payment::whereKey($parent->id)->where('user_id', $userId)->lockForUpdate()->firstOrFail()
                    : null;
                $order = Order::whereKey($orderId)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
                $this->assertOrderCanBePaid($order, $data['method'], $lockedParent);

                if (! $parent && $order->payments()->whereIn('status', ['initializing', 'pending'])->exists()) {
                    throw ValidationException::withMessages([
                        'order_id' => ['This order already has an active payment. Check its status or retry that attempt.'],
                    ]);
                }

                $savedCard = $this->savedCard($data['saved_card_id'] ?? null, $userId, $data['method']);
                $record = IdempotencyKey::create([
                    'user_id' => $userId,
                    'operation' => $operation,
                    'key' => $idempotencyKey,
                    'request_hash' => $requestHash,
                    'response_status' => 201,
                ]);
                $payment = Payment::create([
                    'order_id' => $order->id,
                    'user_id' => $userId,
                    'parent_payment_id' => $lockedParent?->id,
                    'saved_card_id' => $savedCard?->id,
                    'provider' => 'paystack',
                    'method' => $data['method'],
                    'reference' => $this->reference($order),
                    'status' => 'initializing',
                    'currency' => $order->currency,
                    'amount' => $order->grand_total,
                    'amount_minor' => $this->toMinorUnits($order->grand_total),
                    'customer_email' => strtolower($order->contact_email),
                    'callback_url' => $data['callback_url'] ?? null,
                ]);
                $record->update(['resource_type' => Payment::class, 'resource_id' => $payment->id]);

                return $payment;
            }, 3);
        } catch (QueryException $exception) {
            $existing = $this->findIdempotencyKey($userId, $operation, $idempotencyKey);
            if (! $existing) {
                throw $exception;
            }

            return $this->replay($existing, $requestHash);
        }

        if ($payment->status !== 'initializing') {
            return ['payment' => $payment, 'replayed' => true];
        }

        try {
            $providerData = $payment->saved_card_id
                ? $this->paystack->chargeAuthorization($this->providerPayload($payment, $payment->savedCard->authorization_code))
                : $this->paystack->initializeTransaction($this->providerPayload($payment));

            if ($payment->saved_card_id) {
                if (! empty($providerData['authorization_url'])) {
                    $payment->update([
                        'status' => 'pending',
                        'authorization_url' => $providerData['authorization_url'],
                        'access_code' => $providerData['access_code'] ?? null,
                    ]);
                } else {
                    $payment = $this->settlement->apply($payment, $providerData);
                }
            } else {
                if (($providerData['reference'] ?? null) !== $payment->reference) {
                    throw new PaymentGatewayException('Paystack returned an unexpected payment reference.');
                }
                $payment->update([
                    'status' => 'pending',
                    'authorization_url' => $providerData['authorization_url'] ?? null,
                    'access_code' => $providerData['access_code'] ?? null,
                ]);
            }
        } catch (PaymentGatewayException $exception) {
            $payment->update([
                'status' => $exception->outcomeUnknown ? 'pending' : 'failed',
                'failure_message' => $exception->getMessage(),
            ]);
            if (! $exception->outcomeUnknown) {
                $payment->order()->where('payment_status', '!=', 'paid')->update(['payment_status' => 'failed']);
            }
            throw $exception;
        }

        return ['payment' => $payment->fresh(), 'replayed' => false];
    }

    private function assertOrderCanBePaid(Order $order, string $method, ?Payment $parent): void
    {
        if ($order->payment_status === 'paid') {
            throw ValidationException::withMessages(['order_id' => ['This order is already paid.']]);
        }
        if (in_array($order->status, ['cancelled', 'delivered'], true)) {
            throw ValidationException::withMessages(['order_id' => ['This order can no longer be paid.']]);
        }
        if ($order->payment_method === 'cash_on_delivery') {
            throw ValidationException::withMessages(['method' => ['Cash-on-delivery orders do not use online payment initialization.']]);
        }
        if ($order->payment_method !== $method) {
            throw ValidationException::withMessages(['method' => ['The payment method must match the order.']]);
        }
        if ($parent && ($parent->order_id !== $order->id || $parent->user_id !== $order->user_id
            || ! in_array($parent->status, ['failed', 'abandoned'], true))) {
            throw ValidationException::withMessages(['payment' => ['Only a failed or abandoned payment can be retried.']]);
        }
    }

    private function savedCard(?int $savedCardId, int $userId, string $method): ?SavedCard
    {
        if (! $savedCardId) {
            return null;
        }
        if ($method !== 'card') {
            throw ValidationException::withMessages(['saved_card_id' => ['Saved cards can only be used for card payments.']]);
        }

        $card = SavedCard::whereKey($savedCardId)->where('user_id', $userId)->firstOrFail();
        if (! $card->reusable || $card->isExpired()) {
            throw ValidationException::withMessages(['saved_card_id' => ['The selected card cannot be charged.']]);
        }

        return $card;
    }

    private function providerPayload(Payment $payment, ?string $authorizationCode = null): array
    {
        return array_filter([
            'email' => $payment->customer_email,
            'amount' => (string) $payment->amount_minor,
            'currency' => strtoupper($payment->currency),
            'reference' => $payment->reference,
            'callback_url' => $payment->callback_url,
            'channels' => $authorizationCode ? null : [$payment->method === 'card' ? 'card' : 'bank_transfer'],
            'authorization_code' => $authorizationCode,
            'metadata' => json_encode([
                'order_id' => $payment->order_id,
                'payment_id' => $payment->id,
                'cancel_action' => $payment->callback_url,
            ], JSON_THROW_ON_ERROR),
        ], fn ($value) => $value !== null);
    }

    private function requestHash(int $orderId, array $data, ?Payment $parent): string
    {
        return hash('sha256', json_encode([
            'order_id' => $orderId,
            'method' => $data['method'],
            'callback_url' => $data['callback_url'] ?? null,
            'saved_card_id' => $data['saved_card_id'] ?? null,
            'parent_payment_id' => $parent?->id,
        ], JSON_THROW_ON_ERROR));
    }

    private function findIdempotencyKey(int $userId, string $operation, string $key): ?IdempotencyKey
    {
        return IdempotencyKey::where('user_id', $userId)->where('operation', $operation)->where('key', $key)->first();
    }

    /**
     * @return array{payment: Payment, replayed: bool}
     */
    private function replay(IdempotencyKey $record, string $requestHash): array
    {
        if (! hash_equals($record->request_hash, $requestHash)) {
            throw new ConflictHttpException('This idempotency key was already used with different payment details.');
        }
        if ($record->resource_type !== Payment::class || ! $record->resource_id) {
            throw new ConflictHttpException('A payment request with this idempotency key is still being processed.');
        }

        return ['payment' => Payment::findOrFail($record->resource_id), 'replayed' => true];
    }

    private function reference(Order $order): string
    {
        return 'LMT-PAY-'.$order->id.'-'.Str::upper(Str::random(16));
    }

    private function toMinorUnits(mixed $amount): int
    {
        [$whole, $fraction] = explode('.', number_format((float) $amount, 2, '.', ''));

        return ((int) $whole * 100) + (int) $fraction;
    }
}
