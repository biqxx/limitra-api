<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\OrderResource;
use App\Models\Order\Order;
use App\Services\Order\OrderLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends BaseController
{
    private const DETAIL_RELATIONS = [
        'items.product',
        'items.variant',
        'latestPayment',
        'shipment.events',
        'statusEvents',
        'user',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date', 'after_or_equal:from_date'],
            'user_id' => ['sometimes', 'integer', 'exists:users,id'],
            'status' => ['sometimes', 'in:pending_payment,confirmed,processing,shipped,in_transit,delivered,cancelled'],
            'payment_status' => ['sometimes', 'in:unpaid,pending,paid,failed,refunded,partially_refunded'],
            'q' => ['sometimes', 'string', 'max:100'],
            'sort_by' => ['sometimes', 'in:created_at,status,grand_total'],
            'sort' => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $search = isset($data['q']) ? '%'.$data['q'].'%' : null;
        $orders = Order::with(['items', 'latestPayment', 'shipment', 'user'])
            ->when($data['from_date'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($data['to_date'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($data['user_id'] ?? null, fn ($query, $userId) => $query->where('user_id', $userId))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['payment_status'] ?? null, fn ($query, $status) => $query->where('payment_status', $status))
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('number', 'like', $search)
                    ->orWhere('contact_email', 'like', $search)
                    ->orWhereHas('items', fn ($items) => $items->where('product_name', 'like', $search));
            }))
            ->orderBy($data['sort_by'] ?? 'created_at', $data['sort'] ?? 'desc')
            ->paginate($data['per_page'] ?? 20);

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

    public function show(Order $order): JsonResponse
    {
        return $this->success(new OrderResource($order->load(self::DETAIL_RELATIONS)));
    }

    public function updateStatus(
        Request $request,
        Order $order,
        OrderLifecycleService $lifecycle,
    ): JsonResponse {
        $data = $request->validate([
            'status' => ['required', 'in:processing,in_transit,delivered'],
            'note' => ['required', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:150'],
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
        ]);
        $order = $lifecycle->transition($order, $data['status'], $data, (int) auth('api')->id());

        return $this->success(new OrderResource($order->load(self::DETAIL_RELATIONS)), 'Order status updated.');
    }

    public function ship(Request $request, Order $order, OrderLifecycleService $lifecycle): JsonResponse
    {
        $data = $request->validate([
            'courier' => ['required', 'string', 'max:100'],
            'tracking_number' => ['required', 'string', 'max:150'],
            'estimated_delivery_at' => ['nullable', 'date', 'after:now'],
            'location' => ['nullable', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);
        $order = $lifecycle->ship($order, $data, (int) auth('api')->id());

        return $this->success(new OrderResource($order->load(self::DETAIL_RELATIONS)), 'Order shipped.');
    }

    public function update(Request $request, Order $order): JsonResponse
    {
        return $this->error('Use the explicit order status or shipping action.', 405);
    }

    public function destroy(Order $order): JsonResponse
    {
        return $this->error('Placed orders cannot be deleted.', 405);
    }
}
