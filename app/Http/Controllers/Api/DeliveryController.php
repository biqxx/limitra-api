<?php

namespace App\Http\Controllers\Api;

use App\Models\Address\Address;
use App\Models\Cart\Cart;
use App\Models\Commerce\PickupLocation;
use App\Services\Cart\CartIdentityService;
use App\Services\Commerce\DeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryController extends BaseController
{
    public function options(Request $request, CartIdentityService $identity, DeliveryService $delivery): JsonResponse
    {
        $data = $request->validate([
            'cart_id' => ['required', 'integer', 'exists:carts,id'],
            'address_id' => ['nullable', 'integer', 'exists:addresses,id'],
            'country' => ['required_without:address_id', 'string', 'size:2'],
            'state' => ['required_without:address_id', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
        ]);
        $cart = Cart::findOrFail($data['cart_id']);
        $identity->authorize($request, $cart);

        if (isset($data['address_id'])) {
            $address = Address::findOrFail($data['address_id']);
            abort_unless(auth('api')->check() && $address->user_id === auth('api')->id(), 403);
            $data['country'] = $address->country;
            $data['state'] = $address->state;
            $data['city'] = $address->city;
        }

        return $this->success($delivery->options($cart, $data['country'], $data['state'], $data['city'] ?? null));
    }

    public function pickupLocations(Request $request): JsonResponse
    {
        $data = $request->validate([
            'country' => ['nullable', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:100'],
            'city' => ['nullable', 'string', 'max:100'],
        ]);
        $locations = PickupLocation::where('active', true)
            ->where('country', strtoupper($data['country'] ?? 'NG'))
            ->when($data['state'] ?? null, fn ($query, $state) => $query->whereRaw('LOWER(state) = ?', [strtolower($state)]))
            ->when($data['city'] ?? null, fn ($query, $city) => $query->whereRaw('LOWER(city) = ?', [strtolower($city)]))
            ->orderBy('name')->get();

        return $this->success($locations);
    }
}
