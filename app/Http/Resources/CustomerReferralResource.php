<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CustomerReferralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'referred_customer' => $this->whenLoaded('referredUser', fn (): array => [
                'id' => $this->referredUser->id,
                'username' => $this->referredUser->username,
            ]),
            'qualifying_order_id' => $this->qualifying_order_id,
            'reward_amount' => $this->reward_amount_minor === null
                ? null
                : number_format($this->reward_amount_minor / 100, 2, '.', ''),
            'reward_amount_minor' => $this->reward_amount_minor,
            'reward_currency' => $this->reward_currency,
            'qualified_at' => $this->qualified_at,
            'rewarded_at' => $this->rewarded_at,
            'created_at' => $this->created_at,
        ];
    }
}
