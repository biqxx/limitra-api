<?php

namespace App\Models\Reward;

use App\Models\Payment\WalletTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RewardSpin extends Model
{
    protected $fillable = [
        'public_id', 'reward_campaign_id', 'reward_prize_id', 'user_id', 'wallet_transaction_id',
        'status', 'idempotency_key', 'selection_roll', 'total_weight_basis_points',
        'configuration_version', 'configuration_snapshot', 'rewarded_at',
    ];

    protected $hidden = ['idempotency_key', 'selection_roll'];

    protected function casts(): array
    {
        return [
            'selection_roll' => 'integer',
            'total_weight_basis_points' => 'integer',
            'configuration_version' => 'integer',
            'configuration_snapshot' => 'array',
            'rewarded_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(RewardCampaign::class, 'reward_campaign_id');
    }

    public function prize(): BelongsTo
    {
        return $this->belongsTo(RewardPrize::class, 'reward_prize_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }
}
