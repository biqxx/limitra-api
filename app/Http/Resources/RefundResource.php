<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_id' => $this->return_request_id,
            'order_id' => $this->order_id,
            'reference' => $this->reference,
            'provider' => $this->provider,
            'method' => $this->method,
            'status' => $this->status,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'provider_reference' => $this->provider_reference,
            'reason' => $this->reason,
            'failure_message' => $this->failure_message,
            'processed_at' => $this->processed_at,
            'created_at' => $this->created_at,
        ];
    }
}
