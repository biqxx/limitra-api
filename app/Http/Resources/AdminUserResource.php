<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...(new UserResource($this->resource))->toArray($request),
            'status' => $this->status->value,
            'suspended_at' => $this->suspended_at,
            'suspended_until' => $this->suspended_until,
            'suspension_reason' => $this->suspension_reason,
            'suspended_by' => $this->suspended_by,
        ];
    }
}
