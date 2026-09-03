<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'category' => $this->category,
            'subject' => $this->subject,
            'status' => $this->status,
            'priority' => $this->priority,
            'contact' => [
                'name' => $this->contact_name,
                'email' => $this->contact_email,
            ],
            'order' => $this->whenLoaded('order', fn (): ?array => $this->order ? [
                'id' => $this->order->id,
                'number' => $this->order->number,
            ] : null),
            'assignee' => $this->whenLoaded('assignee', fn (): ?array => $this->assignee ? [
                'id' => $this->assignee->id,
                'username' => $this->assignee->username,
            ] : null),
            'message_count' => $this->whenCounted('messages'),
            'messages' => SupportTicketMessageResource::collection($this->whenLoaded('messages')),
            'first_response_due_at' => $this->first_response_due_at,
            'resolution_due_at' => $this->resolution_due_at,
            'first_responded_at' => $this->first_responded_at,
            'resolved_at' => $this->resolved_at,
            'closed_at' => $this->closed_at,
            'last_message_at' => $this->last_message_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
