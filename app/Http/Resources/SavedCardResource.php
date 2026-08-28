<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SavedCardResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'provider' => $this->provider,
            'brand' => $this->brand,
            'last4' => $this->last4,
            'expiry_month' => $this->expiry_month,
            'expiry_year' => $this->expiry_year,
            'cardholder_name' => $this->cardholder_name,
            'reusable' => $this->reusable,
            'is_default' => $this->is_default,
            'is_expired' => $this->isExpired(),
            // card_token intentionally omitted (hidden on model)
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
