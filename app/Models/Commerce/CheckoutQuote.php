<?php

namespace App\Models\Commerce;

use App\Models\Address\Address;
use App\Models\Cart\Cart;
use App\Models\Order\Order;
use App\Models\Payment\SavedCard;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CheckoutQuote extends Model
{
    use HasUuids;

    protected $fillable = [
        'quote_id', 'user_id', 'cart_id', 'address_id', 'delivery_method_id', 'saved_card_id', 'promotion_id',
        'payment_method', 'currency', 'subtotal', 'discount_total', 'shipping_total', 'wallet_credit',
        'wallet_snapshot', 'grand_total', 'address_snapshot', 'shipping_snapshot', 'promotion_snapshot',
        'warnings', 'expires_at', 'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2', 'discount_total' => 'decimal:2', 'shipping_total' => 'decimal:2',
            'wallet_credit' => 'decimal:2', 'grand_total' => 'decimal:2', 'address_snapshot' => 'array',
            'wallet_snapshot' => 'array', 'shipping_snapshot' => 'array', 'promotion_snapshot' => 'array', 'warnings' => 'array',
            'expires_at' => 'datetime', 'consumed_at' => 'datetime',
        ];
    }

    public function uniqueIds(): array
    {
        return ['quote_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'quote_id';
    }

    public function items(): HasMany
    {
        return $this->hasMany(CheckoutQuoteItem::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function deliveryMethod(): BelongsTo
    {
        return $this->belongsTo(DeliveryMethod::class);
    }

    public function savedCard(): BelongsTo
    {
        return $this->belongsTo(SavedCard::class);
    }

    public function promotion(): BelongsTo
    {
        return $this->belongsTo(Promotion::class);
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }
}
