<?php

namespace App\Models\Commerce;

use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckoutQuoteItem extends Model
{
    protected $fillable = ['product_id', 'variant_id', 'product_name', 'sku', 'selected_options', 'quantity', 'unit_price', 'line_total'];

    protected function casts(): array
    {
        return ['selected_options' => 'array', 'quantity' => 'integer', 'unit_price' => 'decimal:2', 'line_total' => 'decimal:2'];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(CheckoutQuote::class, 'checkout_quote_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'variant_id');
    }
}
