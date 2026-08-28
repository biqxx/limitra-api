<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CartResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'status' => $this->status,
            'currency' => $this->currency,
            'items' => CartItemResource::collection($this->whenLoaded('items')),
            'item_count' => $this->whenLoaded('items', fn () => $this->items->sum('quantity')),
            'subtotal' => $this->whenLoaded('items', function () {
                return number_format($this->items->sum(function ($item) {
                    $price = $item->variant?->price ?? $item->product->price;

                    return (float) $price * $item->quantity;
                }), 2, '.', '');
            }),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
