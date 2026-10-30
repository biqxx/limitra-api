<?php

namespace App\Http\Resources;

use App\Models\Product\InventoryAdjustment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin InventoryAdjustment
 */
class InventoryAdjustmentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'variant_id' => $this->variant_id,
            'quantity_delta' => $this->quantity_delta,
            'quantity_before' => $this->quantity_before,
            'quantity_after' => $this->quantity_after,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'adjusted_by' => $this->whenLoaded('adjustedBy', fn () => [
                'id' => $this->adjustedBy->id,
                'username' => $this->adjustedBy->username,
            ]),
            'product' => $this->whenLoaded('product', fn () => [
                'id' => $this->product->id,
                'name' => $this->product->name,
                'sku' => $this->product->sku,
                'stock' => $this->product->stock,
            ]),
            'variant' => $this->whenLoaded('variant', fn () => [
                'id' => $this->variant->id,
                'sku' => $this->variant->sku,
                'stock' => $this->variant->stock,
            ]),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
