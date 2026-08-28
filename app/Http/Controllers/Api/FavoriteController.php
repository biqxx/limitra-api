<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\FavoriteResource;
use App\Models\Cart\Favorite;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class FavoriteController extends BaseController
{
    #[OA\Get(
        path: '/favorites',
        tags: ['Favorites'],
        summary: 'List favorites',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Favorite list', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $request->validate(['per_page' => ['nullable', 'integer', 'between:1,100']]);

        $favorites = Favorite::with(['product.category', 'product.productImages', 'variant'])
            ->where('user_id', auth('api')->id())
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => FavoriteResource::collection($favorites->getCollection()),
            'pagination' => [
                'current_page' => $favorites->currentPage(),
                'per_page' => $favorites->perPage(),
                'total' => $favorites->total(),
                'last_page' => $favorites->lastPage(),
                'from' => $favorites->firstItem(),
                'to' => $favorites->lastItem(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/favorites',
        tags: ['Favorites'],
        summary: 'Add product to favorites',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_id'],
                properties: [
                    new OA\Property(property: 'product_id', type: 'integer'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Added to favorites', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 200, description: 'Already in favorites', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function store(Request $request, CartService $cartService): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'selected_options' => ['nullable', 'array'],
        ]);

        $product = Product::whereKey($data['product_id'])->where('status', 'active')->firstOrFail();
        $variant = isset($data['variant_id'])
            ? ProductVariant::whereKey($data['variant_id'])->where('product_id', $product->id)->where('status', 'active')->first()
            : null;

        if (isset($data['variant_id']) && ! $variant) {
            return $this->error('The selected variant is unavailable for this product.', 422);
        }

        if (! $variant && $product->variants()->where('status', 'active')->exists()) {
            return $this->error('A variant is required for this product.', 422);
        }

        $options = $variant?->attributes ?? ($data['selected_options'] ?? []);
        if ($variant && isset($data['selected_options'])
            && $cartService->lineKey($product->id, $variant->id, $data['selected_options'])
                !== $cartService->lineKey($product->id, $variant->id, $options)) {
            return $this->error('The selected options do not match the variant.', 422);
        }

        $lineKey = $cartService->lineKey($product->id, $variant?->id, $options);

        $favorite = Favorite::firstOrCreate([
            'user_id' => auth('api')->id(),
            'line_key' => $lineKey,
        ], [
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'selected_options' => $options ?: null,
        ]);

        $status = $favorite->wasRecentlyCreated ? 201 : 200;
        $message = $favorite->wasRecentlyCreated ? 'Added to favorites.' : 'Already in favorites.';

        return $this->success(new FavoriteResource($favorite->load(['product.category', 'product.productImages', 'variant'])), $message, $status);
    }

    #[OA\Get(
        path: '/favorites/{favorite}',
        tags: ['Favorites'],
        summary: 'Get favorite',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'favorite', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Favorite details', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(Favorite $favorite): JsonResponse
    {
        $this->authorize('view', $favorite);

        return $this->success(new FavoriteResource($favorite->load(['product.category', 'product.productImages', 'variant'])));
    }

    public function update(): JsonResponse
    {
        return $this->error('Use store/destroy to manage favorites.', 405);
    }

    #[OA\Delete(
        path: '/favorites/{favorite}',
        tags: ['Favorites'],
        summary: 'Remove from favorites',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'favorite', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Removed from favorites', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function destroy(Favorite $favorite): JsonResponse
    {
        $this->authorize('delete', $favorite);

        $favorite->delete();

        return $this->success(message: 'Removed from favorites.');
    }
}
