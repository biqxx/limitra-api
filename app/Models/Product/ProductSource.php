<?php

namespace App\Models\Product;

use App\Models\User;
use Database\Factories\Product\ProductSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductSource extends Model
{
    /** @use HasFactory<ProductSourceFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'supplier',
        'external_product_id',
        'supplier_url',
        'supplier_price',
        'supplier_currency',
        'available',
        'weight_lb',
        'delivery_days_min',
        'delivery_days_max',
        'restriction_status',
        'validation_status',
        'final_price_ngn',
        'variants',
        'cost_breakdown',
        'source_snapshot',
        'sync_status',
        'last_synced_at',
        'pricing_status',
        'current_price_quote_id',
        'approved_price_quote_id',
        'pricing_approved_by',
        'pricing_approved_at',
        'price_review_required',
    ];

    protected function casts(): array
    {
        return [
            'available' => 'boolean',
            'supplier_price' => 'decimal:4',
            'weight_lb' => 'decimal:4',
            'final_price_ngn' => 'decimal:2',
            'variants' => 'array',
            'cost_breakdown' => 'array',
            'source_snapshot' => 'array',
            'last_synced_at' => 'datetime',
            'pricing_approved_at' => 'datetime',
            'price_review_required' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function priceQuotes(): HasMany
    {
        return $this->hasMany(ProductPriceQuote::class)->latest('id');
    }

    public function currentPriceQuote(): BelongsTo
    {
        return $this->belongsTo(ProductPriceQuote::class, 'current_price_quote_id');
    }

    public function approvedPriceQuote(): BelongsTo
    {
        return $this->belongsTo(ProductPriceQuote::class, 'approved_price_quote_id');
    }

    public function pricingApprovedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pricing_approved_by');
    }
}
