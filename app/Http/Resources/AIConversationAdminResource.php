<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AIConversationAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->platform,
            'status' => $this->status,
            'user' => $this->user ? [
                'id' => $this->user->id,
                'username' => $this->user->username,
                'email' => $this->user->email,
            ] : null,
            'social_account' => $this->socialAccount ? [
                'id' => $this->socialAccount->id,
                'platform' => $this->socialAccount->platform,
                'username' => $this->socialAccount->username,
            ] : null,
            'context' => $this->context,
            'last_message_at' => $this->last_message_at,
            'closed_at' => $this->closed_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'messages' => AIConversationMessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}
