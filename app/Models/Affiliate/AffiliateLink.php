<?php

namespace App\Models\Affiliate;

use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateLink extends Model
{
    protected $fillable = [
        'affiliate_id',
        'product_id',
        'campaign',
        'landing_page',
        'slug',
        'clicks_count',
        'conversions_count',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'clicks_count' => 'integer',
            'conversions_count' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
