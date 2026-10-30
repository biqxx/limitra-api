<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AffiliateCommissionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'affiliate_id' => $this->affiliate_id,
            'affiliate_sale_id' => $this->affiliate_sale_id,
            'sale' => new AffiliateSaleResource($this->whenLoaded('sale')),
            'commission_percent' => $this->commission_percent,
            'commission_amount' => $this->commission_amount,
            'payment_status' => $this->payment_status,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
