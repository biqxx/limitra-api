<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\CartItemResource;
use App\Http\Resources\CartResource;
use App\Models\Cart\Cart;
use App\Models\Cart\CartItem;
use App\Models\Product\Product;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CartItemController extends BaseController
{
    #[OA\Get(
        path: '/cart-items',
        tags: ['Cart Items'],
        summary: 'List cart items',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'cart_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cart item list', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $items = CartItem::with(['product.category', 'product.productImages', 'variant'])
            ->whereHas('cart', fn ($query) => $query->where('user_id', auth('api')->id()))
            ->when($request->cart_id, fn ($q) => $q->where('cart_id', $request->cart_id))
            ->orderByDesc('id')
            ->get();

        return $this->success(CartItemResource::collection($items));
    }

    #[OA\Post(
        path: '/cart-items',
        tags: ['Cart Items'],
        summary: 'Add item to cart',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['cart_id', 'product_id', 'quantity'],
                properties: [
                    new OA\Property(property: 'cart_id', type: 'integer'),
                    new OA\Property(property: 'product_id', type: 'integer'),
                    new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Item added', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'Insufficient stock or checked-out cart', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(Request $request, CartService $cartService): JsonResponse
    {
        $data = $request->validate([
            'cart_id' => ['required', 'exists:carts,id'],
            'product_id' => ['required', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'selected_options' => ['nullable', 'array'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $cart = Cart::findOrFail($data['cart_id']);
        $this->authorize('update', $cart);

        $product = Product::findOrFail($data['product_id']);
        $cart = $cartService->add($cart, $product, $data['variant_id'] ?? null, $data['selected_options'] ?? [], $data['quantity']);

        return $this->success(new CartResource($cart), 'Cart updated.');
    }

    #[OA\Get(
        path: '/cart-items/{cartItem}',
        tags: ['Cart Items'],
        summary: 'Get cart item',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'cartItem', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cart item details', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function show(CartItem $cartItem): JsonResponse
    {
        $this->authorize('view', $cartItem->cart);

        return $this->success(new CartItemResource($cartItem->load(['product.category', 'product.productImages', 'variant'])));
    }

    #[OA\Put(
        path: '/cart-items/{cartItem}',
        tags: ['Cart Items'],
        summary: 'Update cart item quantity',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'cartItem', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['quantity'],
                properties: [
                    new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cart item updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'Insufficient stock', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(Request $request, CartItem $cartItem, CartService $cartService): JsonResponse
    {
        $this->authorize('update', $cartItem->cart);

        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1'],
            'variant_id' => ['sometimes', 'nullable', 'integer', 'exists:product_variants,id'],
            'selected_options' => ['sometimes', 'nullable', 'array'],
        ]);

        $replaceSelection = $request->has('variant_id') || $request->has('selected_options');
        $cart = $cartService->update(
            $cartItem,
            $data['quantity'],
            $request->has('variant_id') ? ($data['variant_id'] ?? null) : $cartItem->variant_id,
            $request->has('selected_options') ? ($data['selected_options'] ?? []) : $cartItem->selected_options,
            $replaceSelection
        );

        return $this->success(new CartResource($cart), 'Cart item updated.');
    }

    #[OA\Delete(
        path: '/cart-items/{cartItem}',
        tags: ['Cart Items'],
        summary: 'Remove cart item',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'cartItem', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Item removed', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function destroy(CartItem $cartItem, CartService $cartService): JsonResponse
    {
        $cart = $cartItem->cart;
        $this->authorize('update', $cart);
        $cartItem->delete();

        return $this->success(new CartResource($cartService->fresh($cart)), 'Item removed from cart.');
    }
}
