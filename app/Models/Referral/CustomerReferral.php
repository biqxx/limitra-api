<?php

namespace App\Models\Referral;

use App\Models\Order\Order;
use App\Models\Payment\WalletTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReferral extends Model
{
    protected $fillable = [
        'referrer_id', 'referred_user_id', 'customer_referral_attribution_id', 'status',
        'qualifying_order_id', 'reward_transaction_id', 'reward_amount_minor',
        'reward_currency', 'policy_snapshot', 'qualified_at', 'rewarded_at', 'rejected_at',
    ];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return [
            'reward_amount_minor' => 'integer',
            'policy_snapshot' => 'array',
            'qualified_at' => 'datetime',
            'rewarded_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_user_id');
    }

    public function attribution(): BelongsTo
    {
        return $this->belongsTo(CustomerReferralAttribution::class, 'customer_referral_attribution_id');
    }

    public function qualifyingOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'qualifying_order_id');
    }

    public function rewardTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'reward_transaction_id');
    }
}
