<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\AdjustInventoryRequest;
use App\Http\Resources\InventoryAdjustmentResource;
use App\Models\Product\InventoryAdjustment;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Services\Product\InventoryLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class InventoryController extends BaseController
{
    #[OA\Get(
        path: '/admin/inventory',
        tags: ['Admin - Inventory'],
        summary: 'List inventory stock levels or adjustments',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['in_stock', 'low_stock', 'out_of_stock'])),
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated inventory stock status', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        // Backward compatibility: If request asks for adjustments or passes adjustment-specific filters without view=stock
        if ($request->input('view') === 'adjustments' || $request->filled('reason') || $request->filled('from_date') || $request->filled('to_date') || ($request->filled('product_id') && ! $request->filled('status') && $request->input('view') !== 'stock')) {
            return $this->adjustments($request);
        }

        $request->validate([
            'status' => ['sometimes', 'string', 'in:in_stock,low_stock,out_of_stock'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = Product::query()
            ->with(['category:id,name', 'variants:id,product_id,sku,stock,low_stock_threshold,attributes'])
            ->latest('id');

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('sku', 'like', "%{$search}%")
                    ->orWhere('brand', 'like', "%{$search}%");
            });
        }

        if ($request->filled('status')) {
            match ($request->input('status')) {
                'out_of_stock' => $query->where('stock', '<=', 0),
                'low_stock' => $query->where('stock', '>', 0)->whereColumn('stock', '<=', 'low_stock_threshold'),
                'in_stock' => $query->whereColumn('stock', '>', 'low_stock_threshold'),
                default => null,
            };
        }

        $perPage = min(max($request->integer('per_page', 20), 1), 100);
        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(function (Product $product): array {
            $status = match (true) {
                $product->stock <= 0 => 'out_of_stock',
                $product->stock <= $product->low_stock_threshold => 'low_stock',
                default => 'in_stock',
            };

            return [
                'id' => $product->id,
                'name' => $product->name,
                'sku' => $product->sku,
                'brand' => $product->brand,
                'category' => $product->category?->name,
                'stock' => (int) $product->stock,
                'low_stock_threshold' => (int) $product->low_stock_threshold,
                'stock_status' => $status,
                'variants_count' => $product->variants->count(),
                'variants' => $product->variants->map(fn (ProductVariant $v): array => [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'attributes' => $v->attributes,
                    'stock' => (int) $v->stock,
                    'low_stock_threshold' => (int) ($v->low_stock_threshold ?? 5),
                    'stock_status' => match (true) {
                        $v->stock <= 0 => 'out_of_stock',
                        $v->stock <= ($v->low_stock_threshold ?? 5) => 'low_stock',
                        default => 'in_stock',
                    },
                ])->values()->all(),
            ];
        });

        return $this->success([
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/admin/inventory/adjustments',
        tags: ['Admin - Inventory'],
        summary: 'List immutable inventory adjustments ledger',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'product_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'variant_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'reason', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'from_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated inventory adjustment history', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function adjustments(Request $request): JsonResponse
    {
        $request->validate([
            'product_id' => ['sometimes', 'integer', 'exists:products,id'],
            'variant_id' => ['sometimes', 'integer', 'exists:product_variants,id'],
            'reason' => ['sometimes', 'string', 'max:50'],
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date', 'after_or_equal:from_date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $adjustments = InventoryAdjustment::query()
            ->with(['adjustedBy', 'product', 'variant'])
            ->when($request->product_id, fn ($q) => $q->where('product_id', $request->product_id))
            ->when($request->variant_id, fn ($q) => $q->where('variant_id', $request->variant_id))
            ->when($request->reason, fn ($q) => $q->where('reason', $request->reason))
            ->when($request->from_date, fn ($q) => $q->whereDate('created_at', '>=', $request->from_date))
            ->when($request->to_date, fn ($q) => $q->whereDate('created_at', '<=', $request->to_date))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => InventoryAdjustmentResource::collection($adjustments->getCollection()),
            'pagination' => [
                'current_page' => $adjustments->currentPage(),
                'last_page' => $adjustments->lastPage(),
                'per_page' => $adjustments->perPage(),
                'total' => $adjustments->total(),
                'from' => $adjustments->firstItem(),
                'to' => $adjustments->lastItem(),
            ],
        ]);
    }

    public function storeAdjustment(Request $request, InventoryLedgerService $ledger): JsonResponse
    {
        $validated = $request->validate([
            'product_id' => ['required_without:variant_id', 'nullable', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'quantity_delta' => ['required', 'integer'],
            'reason' => ['required', 'string', 'in:restock,shrinkage,damage,audit_correction,return_restock,supplier_receipt,manual_adjustment'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $variant = ! empty($validated['variant_id'])
            ? ProductVariant::findOrFail($validated['variant_id'])
            : null;

        $product = ! empty($validated['product_id'])
            ? Product::findOrFail($validated['product_id'])
            : $variant?->product;

        if (! $product) {
            return $this->error('Product is required.', 422);
        }

        $adjustment = $ledger->adjust(
            product: $product,
            variant: $variant,
            delta: (int) $validated['quantity_delta'],
            reason: (string) $validated['reason'],
            notes: $validated['notes'] ?? null,
            actor: $request->user('api'),
        );

        return $this->success(
            new InventoryAdjustmentResource($adjustment->load(['product', 'variant', 'adjustedBy'])),
            'Inventory stock adjusted successfully.',
            201,
        );
    }

    #[OA\Post(
        path: '/admin/inventory/{product}/adjust',
        tags: ['Admin - Inventory'],
        summary: 'Adjust product or variant inventory stock',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['quantity_delta', 'reason'],
                properties: [
                    new OA\Property(property: 'variant_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'quantity_delta', type: 'integer', description: 'Positive to restock, negative to decrement'),
                    new OA\Property(property: 'reason', type: 'string', enum: ['restock', 'shrinkage', 'damage', 'audit_correction', 'return_restock', 'supplier_receipt', 'manual_adjustment']),
                    new OA\Property(property: 'notes', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Inventory adjusted successfully', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function adjust(
        AdjustInventoryRequest $request,
        Product $product,
        InventoryLedgerService $ledger,
    ): JsonResponse {
        $variant = $request->variant_id
            ? ProductVariant::whereKey($request->variant_id)->where('product_id', $product->id)->firstOrFail()
            : null;

        $adjustment = $ledger->adjust(
            product: $product,
            variant: $variant,
            delta: (int) $request->validated('quantity_delta'),
            reason: (string) $request->validated('reason'),
            notes: $request->validated('notes'),
            actor: $request->user('api'),
        );

        return $this->success(
            new InventoryAdjustmentResource($adjustment->load(['product', 'variant', 'adjustedBy'])),
            'Inventory stock adjusted successfully.',
            201,
        );
    }

    public function updateThreshold(Request $request, Product $product): JsonResponse
    {
        $validated = $request->validate([
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'low_stock_threshold' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        if (! empty($validated['variant_id'])) {
            $variant = ProductVariant::whereKey($validated['variant_id'])->where('product_id', $product->id)->firstOrFail();
            $variant->update(['low_stock_threshold' => $validated['low_stock_threshold']]);

            return $this->success($variant->fresh(), 'Variant low stock threshold updated.');
        }

        $product->update(['low_stock_threshold' => $validated['low_stock_threshold']]);

        return $this->success($product->fresh(), 'Product low stock threshold updated.');
    }
}
