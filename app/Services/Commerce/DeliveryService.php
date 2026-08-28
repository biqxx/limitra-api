<?php

namespace App\Services\Commerce;

use App\Models\Cart\Cart;
use App\Models\Commerce\DeliveryZone;

class DeliveryService
{
    public function options(Cart $cart, string $country, string $state, ?string $city): array
    {
        $cart->loadMissing(['items.product', 'items.variant']);
        $subtotal = $cart->items->sum(fn ($item) => (float) ($item->variant?->price ?? $item->product->price) * $item->quantity);
        $zone = DeliveryZone::with(['methods' => fn ($query) => $query->where('delivery_methods.active', true)])
            ->where('country', strtoupper($country))->where('active', true)->orderByDesc('priority')->get()
            ->first(fn (DeliveryZone $candidate) => $this->matches($candidate, $state, $city));

        if (! $zone) {
            return [];
        }

        return $zone->methods->filter(fn ($method) => $method->pivot->active
            && ($method->pivot->minimum_order === null || $subtotal >= (float) $method->pivot->minimum_order))
            ->map(function ($method) use ($subtotal, $zone, $cart) {
                $fee = (float) $method->pivot->fee;
                if ($method->pivot->free_shipping_threshold !== null
                    && $subtotal >= (float) $method->pivot->free_shipping_threshold) {
                    $fee = 0;
                }

                return [
                    'code' => $method->code,
                    'name' => $method->name,
                    'type' => $method->type,
                    'zone' => ['id' => $zone->id, 'name' => $zone->name],
                    'fee' => number_format($fee, 2, '.', ''),
                    'currency' => $cart->currency,
                    'estimated_days' => [
                        'min' => $method->pivot->estimated_days_min,
                        'max' => $method->pivot->estimated_days_max,
                    ],
                ];
            })->values()->all();
    }

    private function matches(DeliveryZone $zone, string $state, ?string $city): bool
    {
        $states = array_map('strtolower', $zone->states ?? []);
        $cities = array_map('strtolower', $zone->cities ?? []);

        return (! $states || in_array(strtolower($state), $states, true))
            && (! $cities || ($city && in_array(strtolower($city), $cities, true)));
    }
}
