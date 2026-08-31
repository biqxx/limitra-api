<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\CartResource;
use App\Jobs\RecordAnalyticsEvent;
use App\Models\Cart\Cart;
use App\Models\Cart\CartItem;
use App\Models\Product\Product;
use App\Services\Analytics\AnalyticsTrackingService;
use App\Services\Cart\CartIdentityService;
use App\Services\Cart\CartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class CartController extends BaseController
{
    // ═══════════════════════════════════════════════════════════════════════
    //  Standard apiResource methods
    // ═══════════════════════════════════════════════════════════════════════

    #[OA\Get(
        path: '/cart',
        tags: ['Cart'],
        summary: 'List carts',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['active', 'checked_out'])),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cart list', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $carts = Cart::with(CartService::RELATIONS)
            ->where('user_id', auth('api')->id())
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->orderByDesc('id')
            ->get();

        return $this->success(CartResource::collection($carts));
    }

    #[OA\Post(
        path: '/cart',
        tags: ['Cart'],
        summary: 'Get or create active cart',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Existing active cart returned', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 201, description: 'New cart created', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function store(): JsonResponse
    {
        $cart = Cart::activeForUser(auth('api')->id());
        $status = $cart->wasRecentlyCreated ? 201 : 200;

        return $this->success(
            new CartResource($cart->load(CartService::RELATIONS)),
            $cart->wasRecentlyCreated ? 'Cart created.' : 'Active cart retrieved.',
            $status
        );
    }

    #[OA\Get(
        path: '/cart/{cart}',
        tags: ['Cart'],
        summary: 'Get cart by ID',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'cart', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cart with items', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(Cart $cart): JsonResponse
    {
        $this->authorize('view', $cart);

        return $this->success(new CartResource($cart->load(CartService::RELATIONS)));
    }

    #[OA\Put(
        path: '/cart/{cart}',
        tags: ['Cart'],
        summary: 'Update cart status',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'cart', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['status'],
                properties: [
                    new OA\Property(property: 'status', type: 'string', enum: ['active', 'checked_out']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Cart updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(Request $request, Cart $cart): JsonResponse
    {
        $this->authorize('update', $cart);

        $data = $request->validate([
            'status' => ['required', 'in:active,checked_out'],
        ]);

        $cart->update($data);

        return $this->success(new CartResource($cart->load(CartService::RELATIONS)), 'Cart updated.');
    }

    #[OA\Delete(
        path: '/cart/{cart}',
        tags: ['Cart'],
        summary: 'Delete cart',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'cart', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cart deleted', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function destroy(Cart $cart): JsonResponse
    {
        $this->authorize('delete', $cart);

        $cart->delete();

        return $this->success(message: 'Cart deleted.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  Named cart-action endpoints
    // ═══════════════════════════════════════════════════════════════════════

    #[OA\Get(
        path: '/cart/active',
        tags: ['Cart'],
        summary: 'Get active cart',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Active cart', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function getActive(Request $request, CartIdentityService $identity): JsonResponse
    {
        $cart = $identity->active($request);

        return $identity->attachToken($this->success(
            new CartResource($cart->load(CartService::RELATIONS)),
            'Active cart retrieved.'
        ));
    }

    #[OA\Post(
        path: '/cart/add',
        tags: ['Cart'],
        summary: 'Add item to active cart',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['product_id', 'quantity'],
                properties: [
                    new OA\Property(property: 'product_id', type: 'integer'),
                    new OA\Property(property: 'quantity', type: 'integer', minimum: 1),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Item added to cart', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 200, description: 'Item quantity updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'Insufficient stock', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function addItem(Request $request, CartService $cartService, CartIdentityService $identity): JsonResponse
    {
        $data = $request->validate([
            'product_id' => ['required', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'selected_options' => ['nullable', 'array'],
            'quantity' => ['required', 'integer', 'min:1'],
        ]);

        $product = Product::findOrFail($data['product_id']);
        $cart = $identity->active($request);
        $cart = $cartService->add(
            $cart,
            $product,
            $data['variant_id'] ?? null,
            $data['selected_options'] ?? [],
            $data['quantity']
        );

        $sessionId = $request->attributes->get('analytics_session_id')
            ?? AnalyticsTrackingService::resolveSessionId($request);
        $userId = auth('api')->id();

        dispatch(RecordAnalyticsEvent::cartEvent(
            $request,
            'add',
            $sessionId,
            $userId,
            $cart->id,
            $product->id,
            $data['quantity'],
            (float) $product->price,
        ));

        return $identity->attachToken($this->success(
            new CartResource($cart),
            'Cart updated.'
        ));
    }

    #[OA\Delete(
        path: '/cart/{cart}/items/{cartItem}',
        tags: ['Cart'],
        summary: 'Remove item from cart',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'cart', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'cartItem', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Item removed', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function removeItem(Request $request, Cart $cart, CartItem $cartItem, CartService $cartService, CartIdentityService $identity): JsonResponse
    {
        $identity->authorize($request, $cart);

        if ($cartItem->cart_id !== $cart->id) {
            return $this->error('Item does not belong to this cart.', 403);
        }

        $cartItem->delete();

        $sessionId = $request->attributes->get('analytics_session_id')
            ?? AnalyticsTrackingService::resolveSessionId($request);

        dispatch(RecordAnalyticsEvent::cartEvent(
            $request,
            'remove',
            $sessionId,
            auth('api')->id(),
            $cart->id,
            $cartItem->product_id,
        ));

        return $this->success(new CartResource($cartService->fresh($cart)), 'Item removed from cart.');
    }

    #[OA\Delete(
        path: '/cart/{cart}/clear',
        tags: ['Cart'],
        summary: 'Clear all items from cart',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'cart', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Cart cleared', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function clear(Request $request, Cart $cart, CartIdentityService $identity): JsonResponse
    {
        $identity->authorize($request, $cart);

        $cart->items()->delete();

        return $this->success(
            new CartResource($cart->load(CartService::RELATIONS)),
            'Cart cleared.'
        );
    }

    public function merge(Request $request, CartService $cartService, CartIdentityService $identity): JsonResponse
    {
        $data = $request->validate(['anonymous_cart_token' => ['nullable', 'string', 'size:64']]);
        $token = $data['anonymous_cart_token'] ?? $identity->token($request);
        $guestCart = $token ? Cart::activeForGuestToken($token) : null;

        if (! $guestCart) {
            return $this->error('Guest cart not found or expired.', 404);
        }

        $cart = $cartService->merge($guestCart, Cart::activeForUser(auth('api')->id()));

        return $this->success(new CartResource($cart), 'Guest cart merged.');
    }
}
