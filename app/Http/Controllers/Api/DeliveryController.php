<?php

namespace App\Http\Controllers\Api;

use App\Models\Address\Address;
use App\Models\Cart\Cart;
use App\Services\Cart\CartIdentityService;
use App\Services\Commerce\DeliveryService;
use App\Services\Logistics\RedStarService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DeliveryController extends BaseController
{
    public function options(Request $request, CartIdentityService $identity, DeliveryService $delivery): JsonResponse
    {
        $data = $request->validate([
            'cart_id' => ['required', 'integer', 'exists:carts,id'],
            'address_id' => ['nullable', 'integer', 'exists:addresses,id'],
            'redstar_city_code' => ['required_without:address_id', 'string', 'max:20'],
            'redstar_town_id' => ['required_without:address_id', 'integer', 'min:1'],
            'pickup_type' => ['nullable', 'integer', 'min:1'],
            'country' => ['nullable', 'string', 'size:2'],
            'state' => ['nullable', 'string', 'max:100'],
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
            $data['redstar_city_code'] = $address->redstar_city_code;
            $data['redstar_town_id'] = $address->redstar_town_id;
        }

        try {
            $options = $delivery->options(
                cart: $cart,
                country: $data['country'] ?? 'NG',
                state: $data['state'] ?? '',
                city: $data['city'] ?? null,
                redstarCityCode: $data['redstar_city_code'] ?? null,
                redstarTownId: isset($data['redstar_town_id']) ? (int) $data['redstar_town_id'] : null,
                pickupType: isset($data['pickup_type']) ? (int) $data['pickup_type'] : null,
            );
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 502);
        }

        return $this->success($options);
    }

    public function pickupLocations(Request $request, RedStarService $redStar): JsonResponse
    {
        $data = $request->validate([
            'city_code' => ['required', 'string', 'max:20'],
        ]);

        try {
            return $this->success($redStar->dropOffLocations($data['city_code']));
        } catch (RuntimeException $exception) {
            return $this->error($exception->getMessage(), 502);
        }
    }
}
