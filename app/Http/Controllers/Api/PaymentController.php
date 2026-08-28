<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentGatewayException;
use App\Http\Resources\PaymentResource;
use App\Models\Payment\Payment;
use App\Services\Payment\PaymentInitializationService;
use App\Services\Payment\PaymentSettlementService;
use App\Services\Payment\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PaymentController extends BaseController
{
    public function initialize(Request $request, PaymentInitializationService $payments): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer'],
            'method' => ['required', 'in:card,bank_transfer'],
            'callback_url' => ['required', 'url:http,https', 'max:2000'],
            'saved_card_id' => ['nullable', 'integer'],
        ]);

        try {
            $result = $payments->initialize(
                $data['order_id'],
                $data,
                (int) auth('api')->id(),
                $this->idempotencyKey($request),
            );
        } catch (PaymentGatewayException $exception) {
            return $this->error($exception->getMessage(), 502);
        }

        return $this->success(
            new PaymentResource($result['payment']),
            $result['replayed'] ? 'Payment already initialized.' : 'Payment initialized.',
            $result['replayed'] ? 200 : 201,
        );
    }

    public function show(Payment $payment): JsonResponse
    {
        $this->authorizePayment($payment);

        return $this->success(new PaymentResource($payment));
    }

    public function status(
        string $reference,
        PaystackService $paystack,
        PaymentSettlementService $settlement,
    ): JsonResponse {
        $payment = Payment::where('reference', $reference)
            ->where('user_id', auth('api')->id())
            ->firstOrFail();

        if ($payment->status !== 'succeeded') {
            try {
                $payment = $settlement->apply($payment, $paystack->verifyTransaction($payment->reference));
            } catch (PaymentGatewayException $exception) {
                return $this->error($exception->getMessage(), 502);
            }
        }

        return $this->success(new PaymentResource($payment));
    }

    public function retry(
        Request $request,
        Payment $payment,
        PaymentInitializationService $payments,
    ): JsonResponse {
        $this->authorizePayment($payment, ownerOnly: true);
        $data = $request->validate([
            'method' => ['required', 'in:card,bank_transfer'],
            'callback_url' => ['required', 'url:http,https', 'max:2000'],
            'saved_card_id' => ['nullable', 'integer'],
        ]);

        try {
            $result = $payments->initialize(
                $payment->order_id,
                $data,
                (int) auth('api')->id(),
                $this->idempotencyKey($request),
                $payment,
            );
        } catch (PaymentGatewayException $exception) {
            return $this->error($exception->getMessage(), 502);
        }

        return $this->success(
            new PaymentResource($result['payment']),
            $result['replayed'] ? 'Payment retry already initialized.' : 'Payment retry initialized.',
            $result['replayed'] ? 200 : 201,
        );
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        Validator::make(['idempotency_key' => $key], [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ])->validate();

        return (string) $key;
    }

    private function authorizePayment(Payment $payment, bool $ownerOnly = false): void
    {
        $user = auth('api')->user();
        if ($payment->user_id !== $user->id && ($ownerOnly || ! $user->isStaff())) {
            abort(403, 'Forbidden.');
        }
    }
}
