<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\CheckoutQuoteResource;
use App\Services\Commerce\CheckoutQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends BaseController
{
    public function quote(Request $request, CheckoutQuoteService $quotes): JsonResponse
    {
        $data = $request->validate([
            'cart_id' => ['required', 'integer', 'exists:carts,id'],
            'address_id' => ['required', 'integer', 'exists:addresses,id'],
            'delivery_method' => ['required', 'string', 'exists:delivery_methods,code'],
            'payment_method' => ['required', 'in:card,bank_transfer,cash_on_delivery'],
            'saved_card_id' => ['nullable', 'integer', 'exists:saved_cards,id'],
            'promotion_code' => ['nullable', 'string', 'max:50'],
            'use_wallet_credit' => ['nullable', 'boolean'],
        ]);

        return $this->success(new CheckoutQuoteResource($quotes->create($data, auth('api')->id())), 'Checkout quote created.', 201);
    }
}
