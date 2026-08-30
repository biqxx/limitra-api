<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ReturnRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'order_id' => $this->order_id,
            'status' => $this->status,
            'resolution' => $this->resolution,
            'currency' => $this->currency,
            'requested_total' => $this->requested_total,
            'approved_total' => $this->approved_total,
            'notes' => $this->notes,
            'admin_notes' => $this->admin_notes,
            'rejection_reason' => $this->rejection_reason,
            'approved_at' => $this->approved_at,
            'cancelled_at' => $this->cancelled_at,
            'completed_at' => $this->completed_at,
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => $item->id,
                'order_item_id' => $item->order_item_id,
                'product_id' => $item->orderItem?->product_id,
                'product_name' => $item->orderItem?->product_name,
                'sku' => $item->orderItem?->sku,
                'quantity' => $item->quantity,
                'approved_quantity' => $item->approved_quantity,
                'reason' => $item->reason,
                'notes' => $item->notes,
                'unit_price' => $item->unit_price,
                'requested_amount' => $item->requested_amount,
                'approved_amount' => $item->approved_amount,
            ])),
            'images' => $this->whenLoaded('images', fn () => $this->images->map(fn ($image) => [
                'id' => $image->id,
                'url' => Storage::disk('public')->url($image->path),
                'position' => $image->sort_order,
            ])),
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'id' => $event->id,
                'from_status' => $event->from_status,
                'to_status' => $event->to_status,
                'source' => $event->source,
                'note' => $event->note,
                'created_at' => $event->created_at,
            ])),
            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
