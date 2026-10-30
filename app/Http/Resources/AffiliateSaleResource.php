<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AffiliateSaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'affiliate_id' => $this->affiliate_id,
            'order_id' => $this->order_id,
            'product_id' => $this->product_id,
            'product' => new ProductResource($this->whenLoaded('product')),
            'buyer_user_id' => $this->buyer_user_id,
            'buyer' => new UserResource($this->whenLoaded('buyer')),
            'sale_type' => $this->sale_type,
            'sale_amount' => $this->sale_amount,
            'commission' => new AffiliateCommissionResource($this->whenLoaded('commission')),
            'created_at' => $this->created_at,
        ];
    }
}
