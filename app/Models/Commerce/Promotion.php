<?php

namespace App\Models\Commerce;

use App\Models\Product\Category;
use App\Models\Product\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Promotion extends Model
{
    protected $fillable = ['code', 'name', 'type', 'value', 'maximum_discount', 'minimum_spend', 'usage_limit', 'per_customer_limit', 'starts_at', 'ends_at', 'active'];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2', 'maximum_discount' => 'decimal:2', 'minimum_spend' => 'decimal:2',
            'starts_at' => 'datetime', 'ends_at' => 'datetime', 'active' => 'boolean',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'promotion_product');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class);
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(PromotionRedemption::class);
    }
}
