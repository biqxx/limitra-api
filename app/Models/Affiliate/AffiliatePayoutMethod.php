<?php

namespace App\Models\Affiliate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliatePayoutMethod extends Model
{
    protected $fillable = [
        'affiliate_id',
        'bank_name',
        'bank_code',
        'account_number',
        'account_name',
        'is_default',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function getMaskedAccountNumberAttribute(): string
    {
        $len = strlen($this->account_number);
        if ($len <= 4) {
            return $this->account_number;
        }

        return str_repeat('*', $len - 4).substr($this->account_number, -4);
    }
}
