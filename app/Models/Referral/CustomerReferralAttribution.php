<?php

namespace App\Models\Referral;

use App\Models\Affiliate\Affiliate;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CustomerReferralAttribution extends Model
{
    protected $fillable = [
        'public_id', 'session_hash', 'type', 'code', 'customer_referral_code_id',
        'affiliate_id', 'ip_hash', 'user_agent_hash', 'converted_user_id',
        'converted_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return ['converted_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function customerCode(): BelongsTo
    {
        return $this->belongsTo(CustomerReferralCode::class, 'customer_referral_code_id');
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function convertedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_user_id');
    }

    public function referral(): HasOne
    {
        return $this->hasOne(CustomerReferral::class);
    }
}
