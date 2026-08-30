<?php

namespace App\Models\Product;

use App\Models\Order\OrderItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Review extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'product_id',
        'order_item_id',
        'user_id',
        'rating',
        'title',
        'body',
        'status',
        'verified_purchase',
        'helpful_count',
        'moderated_by',
        'moderation_reason',
        'moderated_at',
    ];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'verified_purchase' => 'boolean',
            'helpful_count' => 'integer',
            'moderated_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function images(): HasMany
    {
        return $this->hasMany(ReviewImage::class)->orderBy('sort_order')->orderBy('id');
    }

    public function helpfulVotes(): HasMany
    {
        return $this->hasMany(ReviewHelpfulVote::class);
    }
}
