<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\Commerce\DeliveryMethod;
use App\Models\Commerce\DeliveryZone;
use App\Models\Commerce\PickupLocation;
use App\Models\Commerce\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CommerceRuleController extends BaseController
{
    public function zones(): JsonResponse
    {
        return $this->success(DeliveryZone::with('methods')->orderByDesc('priority')->get());
    }

    public function storeZone(Request $request): JsonResponse
    {
        $zone = DeliveryZone::create($request->validate($this->zoneRules()));

        return $this->success($zone, 'Delivery zone created.', 201);
    }

    public function updateZone(Request $request, DeliveryZone $zone): JsonResponse
    {
        $zone->update($request->validate($this->zoneRules(true)));

        return $this->success($zone->fresh('methods'), 'Delivery zone updated.');
    }

    public function methods(): JsonResponse
    {
        return $this->success(DeliveryMethod::orderBy('name')->get());
    }

    public function storeMethod(Request $request): JsonResponse
    {
        $method = DeliveryMethod::create($request->validate($this->methodRules()));

        return $this->success($method, 'Delivery method created.', 201);
    }

    public function updateMethod(Request $request, DeliveryMethod $method): JsonResponse
    {
        $method->update($request->validate($this->methodRules(true, $method)));

        return $this->success($method, 'Delivery method updated.');
    }

    public function setZoneMethod(Request $request, DeliveryZone $zone, DeliveryMethod $method): JsonResponse
    {
        $data = $request->validate([
            'fee' => ['required', 'numeric', 'min:0'],
            'free_shipping_threshold' => ['nullable', 'numeric', 'min:0'],
            'minimum_order' => ['nullable', 'numeric', 'min:0'],
            'estimated_days_min' => ['required', 'integer', 'between:0,90'],
            'estimated_days_max' => ['required', 'integer', 'gte:estimated_days_min', 'max:90'],
            'active' => ['nullable', 'boolean'],
        ]);
        $zone->methods()->syncWithoutDetaching([$method->id => $data]);

        return $this->success($zone->fresh('methods'), 'Delivery pricing updated.');
    }

    public function pickups(): JsonResponse
    {
        return $this->success(PickupLocation::orderBy('name')->get());
    }

    public function storePickup(Request $request): JsonResponse
    {
        $pickup = PickupLocation::create($request->validate($this->pickupRules()));

        return $this->success($pickup, 'Pickup location created.', 201);
    }

    public function updatePickup(Request $request, PickupLocation $pickup): JsonResponse
    {
        $pickup->update($request->validate($this->pickupRules(true, $pickup)));

        return $this->success($pickup, 'Pickup location updated.');
    }

    public function promotions(): JsonResponse
    {
        return $this->success(Promotion::with('products:id', 'categories:id')->latest()->get());
    }

    public function storePromotion(Request $request): JsonResponse
    {
        $data = $request->validate($this->promotionRules());
        $productIds = $data['product_ids'] ?? [];
        $categoryIds = $data['category_ids'] ?? [];
        unset($data['product_ids'], $data['category_ids']);
        $data['code'] = strtoupper($data['code']);
        $promotion = Promotion::create($data);
        $promotion->products()->sync($productIds);
        $promotion->categories()->sync($categoryIds);

        return $this->success($promotion->load('products:id', 'categories:id'), 'Promotion created.', 201);
    }

    public function updatePromotion(Request $request, Promotion $promotion): JsonResponse
    {
        $data = $request->validate($this->promotionRules(true, $promotion));
        if (isset($data['code'])) {
            $data['code'] = strtoupper($data['code']);
        }
        if (array_key_exists('product_ids', $data)) {
            $promotion->products()->sync($data['product_ids']);
        }
        if (array_key_exists('category_ids', $data)) {
            $promotion->categories()->sync($data['category_ids']);
        }
        unset($data['product_ids'], $data['category_ids']);
        $promotion->update($data);

        return $this->success($promotion->fresh()->load('products:id', 'categories:id'), 'Promotion updated.');
    }

    private function zoneRules(bool $sometimes = false): array
    {
        $required = $sometimes ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:150'], 'country' => [$required, 'string', 'size:2'],
            'states' => ['nullable', 'array'], 'states.*' => ['string', 'max:100'],
            'cities' => ['nullable', 'array'], 'cities.*' => ['string', 'max:100'],
            'priority' => ['nullable', 'integer', 'min:0'], 'active' => ['nullable', 'boolean'],
        ];
    }

    private function methodRules(bool $sometimes = false, ?DeliveryMethod $method = null): array
    {
        $required = $sometimes ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:50', Rule::unique('delivery_methods')->ignore($method)],
            'name' => [$required, 'string', 'max:150'], 'type' => [$required, 'in:standard,express,pickup'],
            'active' => ['nullable', 'boolean'],
        ];
    }

    private function pickupRules(bool $sometimes = false, ?PickupLocation $pickup = null): array
    {
        $required = $sometimes ? 'sometimes' : 'required';

        return [
            'delivery_zone_id' => ['nullable', 'exists:delivery_zones,id'],
            'code' => [$required, 'string', 'max:50', Rule::unique('pickup_locations')->ignore($pickup)],
            'name' => [$required, 'string', 'max:150'], 'line1' => [$required, 'string', 'max:255'],
            'city' => [$required, 'string', 'max:100'], 'state' => [$required, 'string', 'max:100'],
            'country' => [$required, 'string', 'size:2'], 'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'], 'active' => ['nullable', 'boolean'],
        ];
    }

    private function promotionRules(bool $sometimes = false, ?Promotion $promotion = null): array
    {
        $required = $sometimes ? 'sometimes' : 'required';

        return [
            'code' => [$required, 'string', 'max:50', Rule::unique('promotions')->ignore($promotion)],
            'name' => [$required, 'string', 'max:150'], 'type' => [$required, 'in:percentage,fixed,free_shipping'],
            'value' => [$required, 'numeric', 'min:0'], 'maximum_discount' => ['nullable', 'numeric', 'min:0'],
            'minimum_spend' => ['nullable', 'numeric', 'min:0'], 'usage_limit' => ['nullable', 'integer', 'min:1'],
            'per_customer_limit' => ['nullable', 'integer', 'min:1'], 'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'], 'active' => ['nullable', 'boolean'],
            'product_ids' => ['sometimes', 'array'], 'product_ids.*' => ['integer', 'exists:products,id'],
            'category_ids' => ['sometimes', 'array'], 'category_ids.*' => ['integer', 'exists:categories,id'],
        ];
    }
}
