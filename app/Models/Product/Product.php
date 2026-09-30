<?php

namespace App\Models\Product;

use App\Models\Cart\CartItem;
use App\Models\Cart\Favorite;
use App\Models\Image;
use App\Models\Order\OrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'category_id',
        'subcategory_id',
        'name',
        'slug',
        'brand',
        'sku',
        'description',
        'price',
        'compare_at_price',
        'currency',
        'stock',
        'low_stock_threshold',
        'status',
        'is_featured',
        'is_bestseller',
        'average_rating',
        'review_count',
        'images',
        'seo_meta',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'images' => 'array',
            'updated_by' => 'array',
            'seo_meta' => 'array',
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'average_rating' => 'decimal:2',
            'stock' => 'integer',
            'low_stock_threshold' => 'integer',
            'review_count' => 'integer',
            'is_featured' => 'boolean',
            'is_bestseller' => 'boolean',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'subcategory_id');
    }

    /** The admin who originally created this product. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cartItems(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function favoritedBy(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function productImages(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable')
            ->where('type', 'product_image')
            ->orderBy('sort_order');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('id');
    }

    public function specifications(): HasMany
    {
        return $this->hasMany(ProductSpecification::class)->orderBy('sort_order')->orderBy('id');
    }

    public function sources(): HasMany
    {
        return $this->hasMany(ProductSource::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        return $query->where($field ?? (is_numeric($value) ? $this->getKeyName() : 'slug'), $value);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Append an editor's user ID to the updated_by list (no duplicates).
     */
    public function recordEditor(int $userId): void
    {
        $editors = $this->updated_by ?? [];

        if (! in_array($userId, $editors)) {
            $editors[] = $userId;
            $this->updated_by = $editors;
        }
    }
}
