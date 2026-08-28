<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'product_id' => $this->product_id,
            'variant_id' => $this->variant_id,
            'name' => $this->product_name,
            'sku' => $this->sku,
            'selected_options' => $this->selected_options ?? [],
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'price_at_purchase' => $this->price_at_purchase,
            'line_total' => $this->line_total,
            'product' => new ProductResource($this->whenLoaded('product')),
            'created_at' => $this->created_at,
        ];
    }
}
