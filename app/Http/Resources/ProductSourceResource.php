<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProductSourceResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier' => $this->supplier,
            'external_product_id' => $this->external_product_id,
            'supplier_url' => $this->supplier_url,
            'supplier_price' => $this->supplier_price,
            'supplier_currency' => $this->supplier_currency,
            'available' => $this->available,
            'weight_lb' => $this->weight_lb,
            'delivery_days_min' => $this->delivery_days_min,
            'delivery_days_max' => $this->delivery_days_max,
            'restriction_status' => $this->restriction_status,
            'validation_status' => $this->validation_status,
            'final_price_ngn' => $this->final_price_ngn,
            'variants' => $this->variants ?? [],
            'cost_breakdown' => $this->cost_breakdown ?? [],
            'sync_status' => $this->sync_status,
            'last_synced_at' => $this->last_synced_at,
            'product' => new ProductResource($this->whenLoaded('product')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
