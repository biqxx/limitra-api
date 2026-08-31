<?php

namespace App\Models\Analytics;

use App\Models\Cart\Cart;
use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'analytics_event_id',
        'user_id',
        'session_id',
        'cart_id',
        'product_id',
        'event_type',
        'quantity',
        'price',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'price' => 'decimal:2',
            'created_at' => 'datetime',
        ];
    }

    public function analyticsEvent(): BelongsTo
    {
        return $this->belongsTo(AnalyticsEvent::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
