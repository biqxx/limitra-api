<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AIConversationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->platform,
            'status' => $this->status,
            'context' => $this->context,
            'last_message_at' => $this->last_message_at,
            'closed_at' => $this->closed_at,
            'created_at' => $this->created_at,
        ];
    }
}
