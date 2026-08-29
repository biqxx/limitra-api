<?php

namespace App\Http\Resources\Settings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BusinessSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'group' => $this->group,
            'label' => $this->label,
            'description' => $this->description,
            'type' => $this->type,
            'value' => $this->value,
            'constraints' => $this->constraints,
            'is_public' => $this->is_public,
            'version' => $this->version,
            'updated_by' => $this->whenLoaded('updatedBy', fn (): ?array => $this->updatedBy ? [
                'id' => $this->updatedBy->id,
                'username' => $this->updatedBy->username,
            ] : null),
            'updated_at' => $this->updated_at,
        ];
    }
}
