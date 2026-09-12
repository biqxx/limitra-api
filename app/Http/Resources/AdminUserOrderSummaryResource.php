<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserOrderSummaryResource extends JsonResource
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
            'number' => $this->number,
            'currency' => $this->currency,
            'grand_total' => $this->grand_total,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'fulfilment_status' => $this->fulfilment_status,
            'created_at' => $this->created_at,
        ];
    }
}
