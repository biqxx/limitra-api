<?php

namespace App\Models\Order;

use App\Models\Address\Address;
use App\Models\Affiliate\AffiliateSale;
use App\Models\Commerce\CheckoutQuote;
use App\Models\Microstore\Microstore;
use App\Models\Payment\Payment;
use App\Models\Payment\Refund;
use App\Models\Support\SupportTicket;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'checkout_quote_id',
        'microstore_id',
        'number',
        'currency',
        'subtotal',
        'discount_total',
        'credit_total',
        'shipping_total',
        'grand_total',
        'total_amount',
        'status',
        'payment_status',
        'fulfilment_status',
        'payment_method',
        'contact_email',
        'notes',
        'delivery_method',
        'estimated_delivery_at',
        'shipping_address_id',
        'shipping_address',
        'cancelled_at',
        'cancellation_reason',
        'cancellation_code',
        'reservation_expired_notification_queued_at',
    ];

    protected static function booted(): void
    {
        static::creating(function (Order $order): void {
            $order->public_id ??= (string) Str::uuid();
        });
    }

    public function resolveRouteBindingQuery($query, $value, $field = null)
    {
        if ($field !== null) {
            return parent::resolveRouteBindingQuery($query, $value, $field);
        }

        $identifier = (string) $value;
        if (Str::isUuid($identifier)) {
            return $query->where($this->qualifyColumn('public_id'), strtolower($identifier));
        }

        $numericId = ltrim($identifier, '0');
        $bigintMax = '9223372036854775807';
        if (! ctype_digit($numericId) || strlen($numericId) > strlen($bigintMax)
            || (strlen($numericId) === strlen($bigintMax) && strcmp($numericId, $bigintMax) > 0)) {
            return $query->whereRaw('1 = 0');
        }

        return parent::resolveRouteBindingQuery($query, $numericId);
    }

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'credit_total' => 'decimal:2',
            'shipping_total' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'shipping_address' => 'array',
            'estimated_delivery_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'reservation_expired_notification_queued_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function checkoutQuote(): BelongsTo
    {
        return $this->belongsTo(CheckoutQuote::class);
    }

    public function microstore(): BelongsTo
    {
        return $this->belongsTo(Microstore::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(InventoryReservation::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    public function latestPayment(): HasOne
    {
        return $this->hasOne(Payment::class)->latestOfMany();
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class);
    }

    public function statusEvents(): HasMany
    {
        return $this->hasMany(OrderStatusEvent::class)->orderByDesc('id');
    }

    public function returnRequests(): HasMany
    {
        return $this->hasMany(ReturnRequest::class);
    }

    public function affiliateSales(): HasMany
    {
        return $this->hasMany(AffiliateSale::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function shippingAddress(): BelongsTo
    {
        return $this->belongsTo(Address::class, 'shipping_address_id');
    }
}
