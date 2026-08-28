<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->order_id,
            'provider' => $this->provider,
            'method' => $this->method,
            'reference' => $this->reference,
            'status' => $this->status,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'authorization_url' => $this->authorization_url,
            'access_code' => $this->when(
                $this->access_code !== null && $request->routeIs('payments.initialize', 'payments.retry'),
                $this->access_code,
            ),
            'channel' => $this->channel,
            'gateway_response' => $this->gateway_response,
            'failure_message' => $this->failure_message,
            'paid_at' => $this->paid_at,
            'verified_at' => $this->verified_at,
            'created_at' => $this->created_at,
        ];
    }
}
