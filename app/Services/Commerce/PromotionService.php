<?php

namespace App\Services\Commerce;

use App\Models\Cart\Cart;
use App\Models\Commerce\Promotion;
use Illuminate\Validation\ValidationException;

class PromotionService
{
    public function validate(string $code, Cart $cart, ?int $userId): array
    {
        $promotion = Promotion::with(['products:id', 'categories:id'])->where('code', strtoupper($code))->first();
        if (! $promotion || ! $promotion->active
            || ($promotion->starts_at && now()->isBefore($promotion->starts_at))
            || ($promotion->ends_at && now()->isAfter($promotion->ends_at))) {
            throw ValidationException::withMessages(['code' => ['This promotion is invalid or inactive.']]);
        }
        if ($promotion->usage_limit !== null && $promotion->redemptions()->count() >= $promotion->usage_limit) {
            throw ValidationException::withMessages(['code' => ['This promotion has reached its usage limit.']]);
        }
        if ($userId && $promotion->per_customer_limit !== null
            && $promotion->redemptions()->where('user_id', $userId)->count() >= $promotion->per_customer_limit) {
            throw ValidationException::withMessages(['code' => ['You have already used this promotion.']]);
        }

        $cart->loadMissing(['items.product', 'items.variant']);
        $productIds = $promotion->products->modelKeys();
        $categoryIds = $promotion->categories->modelKeys();
        $eligibleLines = $cart->items->filter(function ($item) use ($productIds, $categoryIds) {
            if (! $productIds && ! $categoryIds) {
                return true;
            }

            return in_array($item->product_id, $productIds, true)
                || in_array($item->product->category_id, $categoryIds, true)
                || in_array($item->product->subcategory_id, $categoryIds, true);
        });
        $eligibleSubtotal = $eligibleLines->sum(fn ($item) => (float) ($item->variant?->price ?? $item->product->price) * $item->quantity);

        if ($eligibleSubtotal < (float) $promotion->minimum_spend || $eligibleSubtotal <= 0) {
            throw ValidationException::withMessages(['code' => ['The cart does not meet this promotion’s eligibility requirements.']]);
        }

        $discount = match ($promotion->type) {
            'percentage' => $eligibleSubtotal * ((float) $promotion->value / 100),
            'fixed' => min($eligibleSubtotal, (float) $promotion->value),
            default => 0,
        };
        if ($promotion->maximum_discount !== null) {
            $discount = min($discount, (float) $promotion->maximum_discount);
        }

        return [
            'id' => $promotion->id,
            'code' => $promotion->code,
            'name' => $promotion->name,
            'type' => $promotion->type,
            'eligible_subtotal' => number_format($eligibleSubtotal, 2, '.', ''),
            'discount_amount' => number_format($discount, 2, '.', ''),
            'free_shipping' => $promotion->type === 'free_shipping',
        ];
    }
}
