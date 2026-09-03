<?php

namespace App\Models\Referral;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReferralShareEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_id', 'user_id', 'customer_referral_code_id', 'channel', 'shared_url_hash',
        'shared_path', 'ip_hash', 'user_agent_hash', 'occurred_at',
    ];

    protected $hidden = ['shared_url_hash', 'ip_hash', 'user_agent_hash'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(CustomerReferralCode::class, 'customer_referral_code_id');
    }
}
