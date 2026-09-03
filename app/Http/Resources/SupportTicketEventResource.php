<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event' => $this->event,
            'actor_type' => $this->actor_type,
            'actor' => $this->whenLoaded('actor', fn (): ?array => $this->actor ? [
                'id' => $this->actor->id,
                'username' => $this->actor->username,
            ] : null),
            'changes' => $this->changes,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
