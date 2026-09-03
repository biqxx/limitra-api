<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AIConversationMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $metadata = $this->metadata ?? [];

        return [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'products' => $metadata['products'] ?? [],
            'actions' => $metadata['actions'] ?? [],
            'created_at' => $this->created_at,
        ];
    }
}
