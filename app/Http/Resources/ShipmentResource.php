<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'courier' => $this->courier,
            'tracking_number' => $this->tracking_number,
            'status' => $this->status,
            'estimated_delivery_at' => $this->estimated_delivery_at,
            'shipped_at' => $this->shipped_at,
            'delivered_at' => $this->delivered_at,
            'events' => $this->whenLoaded('events', fn () => $this->events->map(fn ($event) => [
                'status' => $event->status,
                'description' => $event->description,
                'location' => $event->location,
                'occurred_at' => $event->occurred_at,
            ])),
        ];
    }
}
