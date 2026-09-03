<?php

namespace App\Models\Reward;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RewardCampaign extends Model
{
    protected $fillable = [
        'public_id', 'name', 'status', 'starts_at', 'ends_at', 'eligibility',
        'coupon_expiry_days', 'version', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'eligibility' => 'array',
            'coupon_expiry_days' => 'integer',
            'version' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function prizes(): HasMany
    {
        return $this->hasMany(RewardPrize::class)->orderBy('sort_order')->orderBy('id');
    }

    public function spins(): HasMany
    {
        return $this->hasMany(RewardSpin::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
