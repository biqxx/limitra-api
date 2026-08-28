<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\SavedCardResource;
use App\Models\Payment\SavedCard;
use App\Services\Payment\PaystackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;
use RuntimeException;

class SavedCardController extends BaseController
{
    #[OA\Get(
        path: '/saved-cards',
        tags: ['Saved Cards'],
        summary: 'List saved cards',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Saved card list', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        $cards = SavedCard::where('user_id', auth('api')->id())
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get();

        return $this->success(SavedCardResource::collection($cards));
    }

    #[OA\Post(
        path: '/saved-cards',
        tags: ['Saved Cards'],
        summary: 'Save a payment card',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['brand', 'last4', 'exp_month', 'exp_year', 'card_token'],
                properties: [
                    new OA\Property(property: 'brand', type: 'string', maxLength: 30, example: 'Visa'),
                    new OA\Property(property: 'last4', type: 'string', example: '4242'),
                    new OA\Property(property: 'exp_month', type: 'integer', minimum: 1, maximum: 12),
                    new OA\Property(property: 'exp_year', type: 'integer', example: 2027),
                    new OA\Property(property: 'card_token', type: 'string'),
                    new OA\Property(property: 'is_default', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Card saved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'Card expired or validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(Request $request, PaystackService $paystack): JsonResponse
    {
        $data = $request->validate([
            'provider' => ['required', 'in:paystack'],
            'reference' => ['required', 'string', 'max:150'],
            'make_default' => ['nullable', 'boolean'],
        ]);

        try {
            $transaction = $paystack->verifyTransaction($data['reference']);
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $authorization = $transaction['authorization'] ?? [];
        $customer = $transaction['customer'] ?? [];
        if (($transaction['status'] ?? null) !== 'success'
            || strtolower($customer['email'] ?? '') !== strtolower(auth('api')->user()->email)
            || empty($authorization['reusable'])
            || empty($authorization['authorization_code'])
            || empty($authorization['signature'])) {
            return $this->error('The verified transaction does not contain a reusable card for this account.', 422);
        }

        $userId = auth('api')->id();
        $makeDefault = (bool) ($data['make_default'] ?? false) || ! SavedCard::where('user_id', $userId)->exists();

        if ($makeDefault) {
            $this->demotePreviousDefault($userId);
        }

        $card = SavedCard::firstOrCreate([
            'signature' => $authorization['signature'],
        ], [
            'user_id' => $userId,
            'provider' => 'paystack',
            'provider_customer_code' => $customer['customer_code'] ?? null,
            'authorization_code' => $authorization['authorization_code'],
            'brand' => $authorization['brand'] ?? 'card',
            'last4' => $authorization['last4'],
            'expiry_month' => (int) $authorization['exp_month'],
            'expiry_year' => (int) $authorization['exp_year'],
            'cardholder_name' => $authorization['account_name'] ?? null,
            'reusable' => true,
            'is_default' => $makeDefault,
        ]);

        if ($card->user_id !== $userId) {
            return $this->error('This payment method is already linked to another account.', 409);
        }
        if ($makeDefault && ! $card->is_default) {
            $card->setAsDefault();
        }

        return $this->success(new SavedCardResource($card), 'Card saved.', 201);
    }

    #[OA\Get(
        path: '/saved-cards/{savedCard}',
        tags: ['Saved Cards'],
        summary: 'Get saved card',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'savedCard', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Card details', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(SavedCard $savedCard): JsonResponse
    {
        $this->authorize('view', $savedCard);

        return $this->success(new SavedCardResource($savedCard));
    }

    #[OA\Put(
        path: '/saved-cards/{savedCard}',
        tags: ['Saved Cards'],
        summary: 'Update saved card',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'savedCard', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'brand', type: 'string', maxLength: 30),
                    new OA\Property(property: 'last4', type: 'string'),
                    new OA\Property(property: 'exp_month', type: 'integer'),
                    new OA\Property(property: 'exp_year', type: 'integer'),
                    new OA\Property(property: 'card_token', type: 'string'),
                    new OA\Property(property: 'is_default', type: 'boolean', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Card updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'Card expired', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(Request $request, SavedCard $savedCard): JsonResponse
    {
        $this->authorize('update', $savedCard);

        $data = $request->validate([
            'cardholder_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        if (! empty($data['is_default'])) {
            $this->demotePreviousDefault($savedCard->user_id, $savedCard->id);
        }

        $savedCard->update($data);

        return $this->success(new SavedCardResource($savedCard), 'Card updated.');
    }

    #[OA\Delete(
        path: '/saved-cards/{savedCard}',
        tags: ['Saved Cards'],
        summary: 'Delete saved card',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'savedCard', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Card removed', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function destroy(SavedCard $savedCard): JsonResponse
    {
        $this->authorize('delete', $savedCard);

        $wasDefault = $savedCard->is_default;
        $savedCard->delete();

        $message = 'Card removed.';

        if ($wasDefault) {
            $next = SavedCard::where('user_id', $savedCard->user_id)
                ->orderByDesc('id')
                ->first();

            if ($next) {
                $next->update(['is_default' => true]);
                $message = 'Card removed. Next card promoted to default.';
            }
        }

        return $this->success(message: $message);
    }

    #[OA\Patch(
        path: '/saved-cards/{savedCard}/set-default',
        tags: ['Saved Cards'],
        summary: 'Set card as default',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'savedCard', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Default card updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'Card is expired', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function setDefault(SavedCard $savedCard): JsonResponse
    {
        $this->authorize('update', $savedCard);

        if ($savedCard->isExpired()) {
            return $this->error('Cannot set an expired card as default.', 422);
        }

        $savedCard->setAsDefault();

        return $this->success(new SavedCardResource($savedCard), 'Default card updated.');
    }

    private function demotePreviousDefault(int $userId, int $excludeId = 0): void
    {
        SavedCard::where('user_id', $userId)
            ->where('is_default', true)
            ->when($excludeId, fn ($q) => $q->where('id', '!=', $excludeId))
            ->update(['is_default' => false]);
    }
}
