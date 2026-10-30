<?php

namespace App\Models\Affiliate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateCommission extends Model
{
    protected $fillable = [
        'affiliate_id',
        'affiliate_sale_id',
        'commission_percent',
        'commission_amount',
        'payment_status',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'commission_percent' => 'decimal:2',
            'commission_amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(AffiliateSale::class, 'affiliate_sale_id');
    }
}
