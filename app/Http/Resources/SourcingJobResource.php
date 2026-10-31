<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SourcingJobResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier' => $this->supplier,
            'external_product_id' => $this->external_product_id,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'error' => $this->error,
            'warning' => $this->warning,
            'supplier_product' => $this->supplier_product,
            'enhanced_content' => $this->enhanced_content,
            'product_id' => $this->product_id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'started_at' => $this->started_at,
            'completed_at' => $this->completed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
