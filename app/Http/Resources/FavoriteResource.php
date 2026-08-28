<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FavoriteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'variant_id' => $this->variant_id,
            'variant' => $this->whenLoaded('variant'),
            'selected_options' => $this->selected_options ?? [],
            'available' => $this->product?->status === 'active'
                && (! $this->variant_id || $this->variant?->status === 'active')
                && ($this->variant?->stock ?? $this->product?->stock ?? 0) > 0,
            'product' => new ProductResource($this->whenLoaded('product')),
            'created_at' => $this->created_at,
        ];
    }
}
