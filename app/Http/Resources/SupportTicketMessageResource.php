<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sender_type' => $this->sender_type,
            'sender' => $this->whenLoaded('sender', fn (): ?array => $this->sender ? [
                'id' => $this->sender->id,
                'username' => $this->sender->username,
            ] : null),
            'message' => $this->message,
            'attachments' => SupportTicketAttachmentResource::collection($this->whenLoaded('attachments')),
            'created_at' => $this->created_at,
        ];
    }
}
