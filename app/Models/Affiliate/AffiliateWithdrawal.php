<?php

namespace App\Models\Affiliate;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateWithdrawal extends Model
{
    protected $fillable = [
        'affiliate_id',
        'payout_method_id',
        'amount',
        'currency',
        'status',
        'reference',
        'admin_notes',
        'provider_reference',
        'processed_by',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'processed_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function payoutMethod(): BelongsTo
    {
        return $this->belongsTo(AffiliatePayoutMethod::class, 'payout_method_id');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
