<?php

namespace App\Models\Product;

use App\Models\User;
use Database\Factories\Product\ProductPriceQuoteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductPriceQuote extends Model
{
    /** @use HasFactory<ProductPriceQuoteFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'supplier_price' => 'decimal:4',
            'weight_lb' => 'decimal:4',
            'proposed_price_ngn' => 'decimal:2',
            'approved_price_ngn' => 'decimal:2',
            'cost_breakdown' => 'array',
            'rules_snapshot' => 'array',
            'missing_inputs' => 'array',
            'blocked_reasons' => 'array',
            'quoted_at' => 'datetime',
            'approved_at' => 'datetime',
        ];
    }

    public function productSource(): BelongsTo
    {
        return $this->belongsTo(ProductSource::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
