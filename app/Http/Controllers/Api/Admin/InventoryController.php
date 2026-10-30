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
        summary: 'List inventory adjustments',
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
    public function index(Request $request): JsonResponse
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
}
