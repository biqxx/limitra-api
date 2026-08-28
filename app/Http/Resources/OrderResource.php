<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'user_id' => $this->user_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'currency' => $this->currency,
            'subtotal' => $this->subtotal,
            'discount_total' => $this->discount_total,
            'credit_total' => $this->credit_total,
            'shipping_total' => $this->shipping_total,
            'grand_total' => $this->grand_total,
            'total_amount' => $this->total_amount,
            'status' => $this->status,
            'payment_status' => $this->payment_status,
            'fulfilment_status' => $this->fulfilment_status,
            'payment_method' => $this->payment_method,
            'contact_email' => $this->contact_email,
            'notes' => $this->notes,
            'delivery_method' => $this->delivery_method,
            'estimated_delivery_at' => $this->estimated_delivery_at,
            'shipping_address' => $this->shipping_address,
            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'payment' => new PaymentResource($this->whenLoaded('latestPayment')),
            'tracking' => null,
            'cancelled_at' => $this->cancelled_at,
            'cancellation_reason' => $this->cancellation_reason,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'deleted_at' => $this->deleted_at,
        ];
    }
}
