<?php

namespace App\Models\Affiliate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AffiliatePayoutMethod extends Model
{
    protected $fillable = [
        'affiliate_id',
        'bank_name',
        'bank_code',
        'account_number',
        'account_name',
        'is_default',
        'is_verified',
        'verified_at',
    ];

    protected $hidden = [
        'account_number',
    ];

    protected $appends = [
        'masked_account_number',
    ];

    protected function casts(): array
    {
        return [
            'account_number' => 'encrypted',
            'is_default' => 'boolean',
            'is_verified' => 'boolean',
            'verified_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(AffiliateWithdrawal::class, 'payout_method_id');
    }

    public function getMaskedAccountNumberAttribute(): string
    {
        $raw = (string) $this->account_number;
        $len = strlen($raw);
        if ($len <= 4) {
            return $raw;
        }

        return str_repeat('*', max(0, $len - 4)).substr($raw, -4);
    }
}
