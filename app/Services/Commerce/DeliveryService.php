<?php

namespace App\Services\Commerce;

use App\Models\Cart\Cart;
use App\Models\Commerce\DeliveryZone;
use App\Services\Logistics\RedStarService;

class DeliveryService
{
    public function __construct(private readonly RedStarService $redStar) {}

    public function options(
        Cart $cart,
        string $country,
        string $state,
        ?string $city,
        ?string $redstarCityCode = null,
        ?int $redstarTownId = null,
        ?int $pickupType = null,
    ): array {
        $cart->loadMissing(['items.product.sources', 'items.variant']);
        $subtotal = $cart->items->sum(fn ($item) => (float) ($item->variant?->price ?? $item->product->effectivePrice()) * $item->quantity);
        $weightKg = $this->cartWeightKg($cart);

        return $this->optionsForSubtotal(
            $subtotal,
            $cart->currency,
            $country,
            $state,
            $city,
            $redstarCityCode,
            $redstarTownId,
            $weightKg,
            $pickupType,
        );
    }

    public function optionsForSubtotal(
        float $subtotal,
        string $currency,
        string $country,
        string $state,
        ?string $city,
        ?string $redstarCityCode = null,
        ?int $redstarTownId = null,
        ?float $weightKg = null,
        ?int $pickupType = null,
    ): array {
        if (! $redstarCityCode || ! $redstarTownId) {
            return [];
        }

        $zone = DeliveryZone::with(['methods' => fn ($query) => $query->where('delivery_methods.active', true)])
            ->where('country', strtoupper($country))
            ->where('active', true)
            ->orderByDesc('priority')
            ->get()
            ->first(fn (DeliveryZone $candidate) => $this->matches($candidate, $state, $city));

        if (! $zone) {
            return [];
        }

        $effectiveWeight = $weightKg ?? (float) config('services.redstar.default_weight_kg', 1.0);
        $feeResult = $this->redStar->calculateDeliveryFee(
            recipientCityCode: $redstarCityCode,
            recipientTownId: $redstarTownId,
            weightKg: $effectiveWeight,
            pickupType: $pickupType,
        );
        $liveFee = $feeResult['fee'];

        return $zone->methods->filter(fn ($method) => $method->pivot->active
            && $method->code === 'redstar'
            && ($method->pivot->minimum_order === null || $subtotal >= (float) $method->pivot->minimum_order))
            ->map(function ($method) use ($subtotal, $zone, $currency, $liveFee, $effectiveWeight) {
                $fee = $liveFee;
                if ($method->pivot->free_shipping_threshold !== null
                    && $subtotal >= (float) $method->pivot->free_shipping_threshold) {
                    $fee = 0.0;
                }

                return [
                    'code' => $method->code,
                    'name' => $method->name,
                    'type' => $method->type,
                    'carrier' => 'redstar',
                    'zone' => ['id' => $zone->id, 'name' => $zone->name],
                    'weight_kg' => round($effectiveWeight, 2),
                    'fee' => number_format($fee, 2, '.', ''),
                    'currency' => $currency,
                    'estimated_days' => [
                        'min' => $method->pivot->estimated_days_min,
                        'max' => $method->pivot->estimated_days_max,
                    ],
                ];
            })->values()->all();
    }

    private function cartWeightKg(Cart $cart): float
    {
        $defaultWeight = (float) config('services.redstar.default_weight_kg', 1.0);
        $total = 0.0;

        foreach ($cart->items as $item) {
            $product = $item->product;
            $itemWeightKg = null;

            if ($product && $product->weight_kg !== null && (float) $product->weight_kg > 0) {
                $itemWeightKg = (float) $product->weight_kg;
            } elseif ($product) {
                $sourceWeightLb = $product->sources->first()?->weight_lb;
                if ($sourceWeightLb !== null && (float) $sourceWeightLb > 0) {
                    $itemWeightKg = (float) $sourceWeightLb * 0.453592;
                }
            }

            $total += ($itemWeightKg ?? $defaultWeight) * $item->quantity;
        }

        return max(0.1, round($total, 2));
    }

    private function matches(DeliveryZone $zone, string $state, ?string $city): bool
    {
        $states = array_map('strtolower', $zone->states ?? []);
        $cities = array_map('strtolower', $zone->cities ?? []);

        return (! $states || in_array(strtolower($state), $states, true))
            && (! $cities || ($city && in_array(strtolower($city), $cities, true)));
    }
}
