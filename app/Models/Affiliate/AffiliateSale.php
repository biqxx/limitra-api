<?php

namespace App\Models\Affiliate;

use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AffiliateSale extends Model
{
    protected $fillable = [
        'affiliate_id',
        'order_id',
        'order_item_id',
        'product_id',
        'buyer_user_id',
        'sale_type',
        'sale_amount',
    ];

    protected function casts(): array
    {
        return [
            'sale_amount' => 'decimal:2',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_user_id');
    }

    public function commission(): HasOne
    {
        return $this->hasOne(AffiliateCommission::class);
    }
}
