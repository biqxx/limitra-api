<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\CartResource;
use App\Http\Resources\OrderResource;
use App\Http\Resources\ShipmentResource;
use App\Jobs\RecordAnalyticsEvent;
use App\Models\Address\Address;
use App\Models\Order\Order;
use App\Services\Analytics\AnalyticsTrackingService;
use App\Services\Order\InvoiceService;
use App\Services\Order\OrderCreationService;
use App\Services\Order\OrderLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use OpenApi\Attributes as OA;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends BaseController
{
    private const DETAIL_RELATIONS = [
        'items.product',
        'items.variant',
        'latestPayment',
        'shipment.events',
        'statusEvents',
        'reservations',
        'refunds',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:pending_payment,confirmed,processing,shipped,in_transit,delivered,cancelled'],
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date', 'after_or_equal:from_date'],
            'q' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $search = isset($data['q']) ? '%'.$data['q'].'%' : null;
        $orders = Order::with(['items', 'latestPayment', 'shipment', 'reservations', 'refunds'])
            ->where('user_id', auth('api')->id())
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['from_date'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($data['to_date'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('number', 'like', $search)
                    ->orWhereHas('items', fn ($items) => $items->where('product_name', 'like', $search));
            }))
            ->latest('id')
            ->paginate($data['per_page'] ?? 15);

        return $this->success([
            'items' => OrderResource::collection($orders->getCollection()),
            'pagination' => [
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
                'total' => $orders->total(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/orders',
        tags: ['Orders'],
        summary: 'Create an order from a checkout quote',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'Idempotency-Key', in: 'header', required: true, schema: new OA\Schema(type: 'string', minLength: 8, maxLength: 100)),
        ],
        responses: [
            new OA\Response(response: 201, description: 'Order created'),
            new OA\Response(response: 409, description: 'Idempotency key conflict'),
            new OA\Response(response: 422, description: 'Quote is no longer valid'),
        ],
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

        $result = $orders->create($data, (int) auth('api')->id(), (string) $idempotencyKey);
        $order = $result['order'];
        $sessionId = $request->attributes->get('analytics_session_id')
            ?? AnalyticsTrackingService::resolveSessionId($request);
        if (! $result['replayed']) {
            dispatch(RecordAnalyticsEvent::orderEvent(
                $request,
                'placed',
                $sessionId,
                $order->user_id,
                $order->id,
                (float) $order->total_amount,
            ));
        }

        return $this->success(
            new OrderResource($order->load(self::DETAIL_RELATIONS)),
            $result['replayed'] ? 'Order already created.' : 'Order created successfully.',
            $result['replayed'] ? 200 : 201,
        );
    }

    public function show(Order $order): JsonResponse
    {
        $this->authorizeView($order);

        return $this->success(new OrderResource($order->load(self::DETAIL_RELATIONS)));
    }

    public function cancel(Request $request, Order $order, OrderLifecycleService $lifecycle): JsonResponse
    {
        $this->authorizeOwner($order);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $order = $lifecycle->cancel($order, (int) auth('api')->id(), $data['reason']);

        return $this->success(new OrderResource($order->load(self::DETAIL_RELATIONS)), 'Order cancelled.');
    }

    public function updateDeliveryAddress(
        Request $request,
        Order $order,
        OrderLifecycleService $lifecycle,
    ): JsonResponse {
        $this->authorizeOwner($order);
        $data = $request->validate(['address_id' => ['required', 'integer']]);
        $address = Address::findOrFail($data['address_id']);
        $order = $lifecycle->updateDeliveryAddress($order, $address, (int) auth('api')->id());

        return $this->success(new OrderResource($order->load(self::DETAIL_RELATIONS)), 'Delivery address updated.');
    }

    public function buyAgain(Request $request, Order $order, OrderLifecycleService $lifecycle): JsonResponse
    {
        $this->authorizeOwner($order);
        $data = $request->validate([
            'item_ids' => ['nullable', 'array', 'min:1'],
            'item_ids.*' => ['integer', 'distinct'],
        ]);
        $cart = $lifecycle->buyAgain($order, (int) auth('api')->id(), $data['item_ids'] ?? null);

        return $this->success(new CartResource($cart), 'Items added to cart.');
    }

    public function invoice(Request $request, Order $order, InvoiceService $invoices): JsonResponse|Response
    {
        $this->authorizeView($order);
        $data = $request->validate(['format' => ['sometimes', 'in:json,pdf']]);
        $invoice = $invoices->data($order);
        if (($data['format'] ?? 'json') === 'pdf') {
            return response($invoices->pdf($invoice), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$invoice['invoice_number'].'.pdf"',
            ]);
        }

        return $this->success($invoice);
    }

    public function tracking(Order $order): JsonResponse
    {
        $this->authorizeView($order);
        $shipment = $order->shipment()->with('events')->first();

        return $this->success($shipment ? new ShipmentResource($shipment) : null);
    }

    private function authorizeView(Order $order): void
    {
        $user = auth('api')->user();
        if ($order->user_id !== $user->id && ! $user->isStaff()) {
            abort(403, 'Forbidden.');
        }
    }

    private function authorizeOwner(Order $order): void
    {
        if ($order->user_id !== auth('api')->id()) {
            abort(403, 'Forbidden.');
        }
    }
}
