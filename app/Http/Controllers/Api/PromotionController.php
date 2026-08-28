<?php

namespace App\Http\Controllers\Api;

use App\Models\Cart\Cart;
use App\Services\Cart\CartIdentityService;
use App\Services\Commerce\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionController extends BaseController
{
    public function validateCode(Request $request, CartIdentityService $identity, PromotionService $promotions): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:50'],
            'cart_id' => ['required', 'integer', 'exists:carts,id'],
        ]);
        $cart = Cart::findOrFail($data['cart_id']);
        $identity->authorize($request, $cart);

        return $this->success($promotions->validate($data['code'], $cart, auth('api')->id()), 'Promotion is valid.');
    }
}
