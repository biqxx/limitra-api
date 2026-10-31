<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\Procurement\SupplierOrder;
use App\Models\Procurement\SupplierOrderEvent;
use App\Services\Admin\AuditEventService;
use App\Services\Sourcing\SupplierServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupplierProcurementController extends BaseController
{
    public function __construct(private readonly AuditEventService $auditEvents) {}

    public function index(Request $request): JsonResponse
    {
        $query = SupplierOrder::query()
            ->with(['order:id,order_number,status', 'createdBy:id,username,email'])
            ->latest('id');

        if ($request->filled('supplier')) {
            $query->where('supplier', (string) $request->input('supplier'));
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('order_id')) {
            $query->where('order_id', $request->integer('order_id'));
        }

        if ($request->filled('tracking_number')) {
            $query->where('tracking_number', 'like', '%'.$request->input('tracking_number').'%');
        }

        $paginator = $query->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function show(SupplierOrder $order): JsonResponse
    {
        return $this->success($order->load([
            'order:id,order_number,status,total_amount',
            'orderItem',
            'createdBy:id,username,email',
            'events.actor:id,username,email',
        ]));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'order_item_id' => ['nullable', 'integer', 'exists:order_items,id'],
            'supplier' => ['required', 'string', 'max:50'],
            'external_supplier_order_id' => ['nullable', 'string', 'max:100'],
            'procurement_cost_amount' => ['nullable', 'numeric', 'min:0'],
            'procurement_cost_currency' => ['nullable', 'string', 'size:3'],
            'exchange_rate' => ['nullable', 'numeric', 'gt:0'],
            'local_cost_amount' => ['nullable', 'numeric', 'min:0'],
            'shipping_cost_amount' => ['nullable', 'numeric', 'min:0'],
            'customs_cost_amount' => ['nullable', 'numeric', 'min:0'],
            'weight_kg' => ['nullable', 'numeric', 'min:0'],
            'volumetric_weight_kg' => ['nullable', 'numeric', 'min:0'],
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $cost = (float) ($validated['procurement_cost_amount'] ?? 0.00);
        $rate = (float) ($validated['exchange_rate'] ?? 1.0000);
        $localCost = isset($validated['local_cost_amount']) ? (float) $validated['local_cost_amount'] : ($cost * $rate);

        $admin = $request->user('api');

        $order = SupplierOrder::create([
            'order_id' => $validated['order_id'] ?? null,
            'order_item_id' => $validated['order_item_id'] ?? null,
            'supplier' => $validated['supplier'],
            'external_supplier_order_id' => $validated['external_supplier_order_id'] ?? null,
            'status' => 'pending',
            'carrier' => $validated['carrier'] ?? null,
            'tracking_number' => $validated['tracking_number'] ?? null,
            'procurement_cost_amount' => $cost,
            'procurement_cost_currency' => strtoupper((string) ($validated['procurement_cost_currency'] ?? 'CNY')),
            'exchange_rate' => $rate,
            'local_cost_amount' => $localCost,
            'shipping_cost_amount' => (float) ($validated['shipping_cost_amount'] ?? 0.00),
            'customs_cost_amount' => (float) ($validated['customs_cost_amount'] ?? 0.00),
            'weight_kg' => $validated['weight_kg'] ?? null,
            'volumetric_weight_kg' => $validated['volumetric_weight_kg'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'metadata' => $validated['metadata'] ?? [],
            'created_by' => $admin?->id,
        ]);

        SupplierOrderEvent::create([
            'supplier_order_id' => $order->id,
            'status' => 'pending',
            'description' => 'Supplier procurement order created.',
            'location' => 'Origin Depot',
            'actor_id' => $admin?->id,
            'source' => 'staff',
            'created_at' => now(),
        ]);

        return $this->success($order->load('events'), 'Supplier order created successfully.', 201);
    }

    public function updateStatus(Request $request, SupplierOrder $order): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['required', 'string', 'in:pending,quoted,purchased,received_at_warehouse,in_inspection,consolidated,dispatched,delivered,cancelled,refunded'],
            'description' => ['required', 'string', 'max:500'],
            'location' => ['nullable', 'string', 'max:100'],
            'carrier' => ['nullable', 'string', 'max:100'],
            'tracking_number' => ['nullable', 'string', 'max:150'],
        ]);

        $admin = $request->user('api');
        $beforeStatus = $order->status;
        $updates = ['status' => $validated['status']];

        if (! empty($validated['carrier'])) {
            $updates['carrier'] = $validated['carrier'];
        }
        if (! empty($validated['tracking_number'])) {
            $updates['tracking_number'] = $validated['tracking_number'];
        }

        if ($validated['status'] === 'purchased' && ! $order->purchased_at) {
            $updates['purchased_at'] = now();
        } elseif ($validated['status'] === 'received_at_warehouse' && ! $order->received_at_hub_at) {
            $updates['received_at_hub_at'] = now();
        } elseif ($validated['status'] === 'dispatched' && ! $order->dispatched_at) {
            $updates['dispatched_at'] = now();
        }

        $order->update($updates);

        SupplierOrderEvent::create([
            'supplier_order_id' => $order->id,
            'status' => $validated['status'],
            'description' => $validated['description'],
            'location' => $validated['location'] ?? null,
            'actor_id' => $admin?->id,
            'source' => 'staff',
            'created_at' => now(),
        ]);

        $this->auditEvents->record(
            action: 'procurement.status_updated',
            actor: $admin,
            subject: $order,
            reason: $validated['status'],
            before: ['status' => $beforeStatus],
            after: ['status' => $validated['status']],
            metadata: [
                'supplier_order_id' => $order->id,
                'description' => $validated['description'],
            ],
        );

        return $this->success($order->fresh()->load('events'), 'Supplier order status updated.');
    }

    public function sync(SupplierOrder $order, SupplierServiceClient $client): JsonResponse
    {
        if (empty($order->external_supplier_order_id)) {
            return $this->error('No external supplier order ID linked to this order.', 422);
        }

        try {
            $data = $client->fetchProcurementStatus($order->supplier, $order->external_supplier_order_id);
            $remoteStatus = $data['status'] ?? 'in_transit';

            $order->update([
                'status' => $remoteStatus,
                'tracking_number' => $data['tracking_number'] ?? $order->tracking_number,
                'carrier' => $data['carrier'] ?? $order->carrier,
            ]);

            SupplierOrderEvent::create([
                'supplier_order_id' => $order->id,
                'status' => $remoteStatus,
                'description' => $data['status_description'] ?? 'Synced from external supplier browser service.',
                'location' => $data['current_location'] ?? 'Cross-border logistics line',
                'source' => 'browser_service',
                'metadata' => $data,
                'created_at' => now(),
            ]);

            return $this->success($order->fresh()->load('events'), 'Synced with supplier service.');
        } catch (\Throwable $e) {
            return $this->error('Failed to sync with supplier browser service: '.$e->getMessage(), 502);
        }
    }
}
