<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AffiliateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'slug' => $this->slug,
            'code' => $this->code,
            'commission_rate' => $this->commission_rate,
            'status' => $this->status,
            'payout_details' => $this->when(
                $request->user()?->isStaff() || $request->user()?->id === $this->user_id,
                $this->payout_details
            ),
            'total_earnings' => $this->total_earnings,
            'total_paid' => $this->total_paid,
            'pending_balance' => $this->pending_balance,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
