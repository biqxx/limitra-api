<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\OrderResource;
use App\Models\Order\Order;
use App\Services\Analytics\AnalyticsTrackingService;
use App\Services\Order\OrderCreationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;

class OrderController extends BaseController
{
    #[OA\Get(
        path: '/orders',
        tags: ['Orders'],
        summary: 'List own orders',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'processing', 'completed', 'cancelled'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated order list', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $orders = Order::with(['items.product', 'shippingAddress'])
            ->where('user_id', auth('api')->id())
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 15));

        return $this->success(OrderResource::collection($orders));
    }

    #[OA\Post(
        path: '/orders',
        tags: ['Orders'],
        summary: 'Create an order from a checkout quote',
        security: [['bearerAuth' => []]],
        description: 'Creates an order once from a valid checkout quote and reserves stock atomically. Requires an Idempotency-Key header.',
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['quote_id', 'payment_method', 'contact_email'],
                properties: [
                    new OA\Property(property: 'quote_id', type: 'string', format: 'uuid'),
                    new OA\Property(property: 'payment_method', type: 'string', enum: ['card', 'bank_transfer', 'cash_on_delivery']),
                    new OA\Property(property: 'contact_email', type: 'string', format: 'email'),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Order created', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 409, description: 'Idempotency key conflict', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Quote expired, changed, or unavailable', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(Request $request, OrderCreationService $orders): JsonResponse
    {
        $data = $request->validate([
            'quote_id' => ['required', 'uuid'],
            'payment_method' => ['required', 'in:card,bank_transfer,cash_on_delivery'],
            'contact_email' => ['required', 'email:rfc', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);
        $idempotencyKey = $request->header('Idempotency-Key');
        Validator::make(['idempotency_key' => $idempotencyKey], [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ])->validate();

        $result = $orders->create($data, (int) auth('api')->id(), $idempotencyKey);
        $order = $result['order'];

        $sessionId = $request->attributes->get('analytics_session_id')
            ?? AnalyticsTrackingService::resolveSessionId($request);

        if (! $result['replayed']) {
            defer(fn () => app(AnalyticsTrackingService::class)->recordOrderEvent(
                'placed', $sessionId, $order->user_id, $order->id, (float) $order->total_amount, [], $request
            ));
        }

        return $this->success(
            new OrderResource($order->load(['items.product', 'items.variant', 'shippingAddress'])),
            $result['replayed'] ? 'Order already created.' : 'Order created successfully.',
            $result['replayed'] ? 200 : 201
        );
    }

    #[OA\Get(
        path: '/orders/{order}',
        tags: ['Orders'],
        summary: 'Get order',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order details', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(Order $order): JsonResponse
    {
        if ($order->user_id !== auth('api')->id() && ! auth('api')->user()->isStaff()) {
            return $this->error('Forbidden.', 403);
        }

        return $this->success(new OrderResource($order->load(['items.product', 'shippingAddress'])));
    }

    #[OA\Put(
        path: '/orders/{order}',
        tags: ['Orders'],
        summary: 'Update order status (staff/admin)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'processing', 'completed', 'cancelled']),
                    new OA\Property(property: 'shipping_address_id', type: 'integer', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Order updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Completed orders cannot be modified', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(Request $request, Order $order): JsonResponse
    {
        if (! auth('api')->user()->isStaff()) {
            return $this->error('Forbidden.', 403);
        }

        $data = $request->validate([
            'status' => ['sometimes', 'required', 'in:pending,processing,completed,cancelled'],
            'shipping_address_id' => ['nullable', 'exists:addresses,id'],
        ]);

        if (isset($data['status']) && $order->status === 'completed') {
            return $this->error('Completed orders cannot be modified.', 422);
        }

        $order->update($data);

        return $this->success(new OrderResource($order->load(['items.product', 'shippingAddress'])), 'Order updated.');
    }

    #[OA\Delete(
        path: '/orders/{order}',
        tags: ['Orders'],
        summary: 'Delete order',
        security: [['bearerAuth' => []]],
        description: 'Only pending or cancelled orders can be deleted.',
        parameters: [
            new OA\Parameter(name: 'order', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Order deleted', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'Order cannot be deleted', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function destroy(Order $order): JsonResponse
    {
        if ($order->user_id !== auth('api')->id() && ! auth('api')->user()->isStaff()) {
            return $this->error('Forbidden.', 403);
        }

        if (! in_array($order->status, ['pending', 'cancelled'])) {
            return $this->error('Only pending or cancelled orders can be deleted.', 422);
        }

        $order->delete();

        return $this->success(message: 'Order deleted.');
    }
}
