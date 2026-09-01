<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminRefundResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'return_id' => $this->return_request_id,
            'reference' => $this->reference,
            'provider' => $this->provider,
            'method' => $this->method,
            'source' => $this->source,
            'status' => $this->status,
            'currency' => $this->currency,
            'amount' => $this->amount,
            'amount_minor' => $this->amount_minor,
            'provider_refund_id' => $this->provider_refund_id,
            'provider_reference' => $this->provider_reference,
            'reason' => $this->reason,
            'failure_message' => $this->failure_message,
            'reconciliation' => [
                'attempts' => $this->reconciliation_attempts,
                'last_checked_at' => $this->last_reconciled_at,
                'next_check_at' => $this->next_reconciliation_at,
            ],
            'customer' => $this->whenLoaded('user', fn (): array => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'order' => $this->whenLoaded('order', fn (): array => [
                'id' => $this->order->id,
                'number' => $this->order->number,
                'status' => $this->order->status,
                'payment_status' => $this->order->payment_status,
            ]),
            'payment' => $this->whenLoaded('payment', fn (): array => [
                'id' => $this->payment->id,
                'reference' => $this->payment->reference,
                'status' => $this->payment->status,
            ]),
            'processed_by' => $this->whenLoaded('processor', fn (): ?array => $this->processor ? [
                'id' => $this->processor->id,
                'name' => $this->processor->name,
                'email' => $this->processor->email,
            ] : null),
            'events' => RefundEventResource::collection($this->whenLoaded('events')),
            'processed_at' => $this->processed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
