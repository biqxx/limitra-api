<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RewardCampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'status' => $this->status,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'eligibility' => $this->eligibility,
            'coupon_expiry_days' => $this->coupon_expiry_days,
            'version' => $this->version,
            'can_spin' => $this->when(
                array_key_exists('can_spin', $this->resource->getAttributes()),
                fn (): bool => (bool) $this->can_spin,
            ),
            'prizes' => $this->whenLoaded('prizes', fn (): array => $this->prizes->map(
                fn ($prize): array => [
                    'id' => $prize->id,
                    'label' => $prize->label,
                    'type' => $prize->type,
                    'value' => number_format($prize->value_minor / 100, 2, '.', ''),
                    'value_minor' => $prize->value_minor,
                    'weight' => number_format($prize->weight_basis_points / 10000, 4, '.', ''),
                    'weight_basis_points' => $prize->weight_basis_points,
                    'inventory_limit' => $prize->inventory_limit,
                    'inventory_awarded' => $prize->inventory_awarded,
                    'inventory_remaining' => $prize->inventory_limit === null
                        ? null
                        : max(0, $prize->inventory_limit - $prize->inventory_awarded),
                    'active' => $prize->active,
                ],
            )->values()->all()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
