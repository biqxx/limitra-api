<?php

namespace App\Models\Referral;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerReferralInvitation extends Model
{
    protected $fillable = [
        'public_id', 'referrer_id', 'customer_referral_code_id', 'channel', 'target_hash',
        'target_ciphertext', 'target_masked', 'status', 'message', 'sent_at', 'failed_at', 'failure_code',
    ];

    protected $hidden = ['target_hash', 'target_ciphertext', 'failure_code'];

    protected function casts(): array
    {
        return [
            'target_ciphertext' => 'encrypted',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    public function referralCode(): BelongsTo
    {
        return $this->belongsTo(CustomerReferralCode::class, 'customer_referral_code_id');
    }
}
