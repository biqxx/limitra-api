<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $currentPrice = $this->variant?->price ?? $this->product?->price;
        $stock = $this->variant?->stock ?? $this->product?->stock ?? 0;
        $available = $this->product?->status === 'active'
            && (! $this->variant_id || $this->variant?->status === 'active')
            && $stock >= $this->quantity;

        return [
            'id' => $this->id,
            'cart_id' => $this->cart_id,
            'quantity' => $this->quantity,
            'variant_id' => $this->variant_id,
            'variant' => $this->whenLoaded('variant'),
            'selected_options' => $this->selected_options ?? [],
            'product' => new ProductResource($this->whenLoaded('product')),
            'currency' => $this->product?->currency,
            'unit_price' => $currentPrice !== null ? number_format((float) $currentPrice, 2, '.', '') : null,
            'unit_price_at_addition' => $this->unit_price_at_addition,
            'line_total' => $currentPrice !== null ? number_format((float) $currentPrice * $this->quantity, 2, '.', '') : null,
            'available' => $available,
            'available_stock' => $stock,
            'price_changed' => $currentPrice !== null
                && number_format((float) $currentPrice, 2, '.', '') !== number_format((float) $this->unit_price_at_addition, 2, '.', ''),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
