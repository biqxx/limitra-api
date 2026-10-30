<?php

namespace App\Models\Affiliate;

use App\Models\Commerce\Promotion;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliatePromoCode extends Model
{
    protected $fillable = [
        'affiliate_id',
        'promotion_id',
        'code',
        'discount_percent',
        'is_active',
        'redemptions_count',
    ];

    protected function casts(): array
    {
        return [
            'discount_percent' => 'decimal:2',
            'is_active' => 'boolean',
            'redemptions_count' => 'integer',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }
}
