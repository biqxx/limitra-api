<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ConnectedAccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'platform' => $this->platform,
            'platform_sender_id' => $this->platform_sender_id,
            'username' => $this->username,
            'metadata' => $this->metadata,
            'connected_at' => $this->created_at,
        ];
    }
}
