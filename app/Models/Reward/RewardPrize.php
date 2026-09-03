<?php

namespace App\Models\Reward;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RewardPrize extends Model
{
    protected $fillable = [
        'reward_campaign_id', 'label', 'type', 'value_minor', 'weight_basis_points',
        'inventory_limit', 'inventory_awarded', 'active', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'value_minor' => 'integer',
            'weight_basis_points' => 'integer',
            'inventory_limit' => 'integer',
            'inventory_awarded' => 'integer',
            'active' => 'boolean',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(RewardCampaign::class, 'reward_campaign_id');
    }

    public function spins(): HasMany
    {
        return $this->hasMany(RewardSpin::class);
    }
}
