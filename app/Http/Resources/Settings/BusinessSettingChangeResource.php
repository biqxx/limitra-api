<?php

namespace App\Http\Resources\Settings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessSettingChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'old_value' => $this->old_value,
            'new_value' => $this->new_value,
            'version' => $this->version,
            'changed_by' => $this->whenLoaded('changedBy', fn (): ?array => $this->changedBy ? [
                'id' => $this->changedBy->id,
                'username' => $this->changedBy->username,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
