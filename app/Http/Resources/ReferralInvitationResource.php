<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ReferralInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'channel' => $this->channel,
            'target' => $this->target_masked,
            'status' => $this->status,
            'message' => $this->message,
            'sent_at' => $this->sent_at,
            'created_at' => $this->created_at,
        ];
    }
}
