<?php

namespace App\Models\Payment;

use App\Models\Order\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends Model
{
    protected $fillable = [
        'order_id',
        'user_id',
        'parent_payment_id',
        'saved_card_id',
        'provider',
        'method',
        'reference',
        'status',
        'currency',
        'amount',
        'amount_minor',
        'customer_email',
        'callback_url',
        'authorization_url',
        'access_code',
        'provider_transaction_id',
        'channel',
        'gateway_response',
        'failure_message',
        'provider_metadata',
        'paid_at',
        'verified_at',
    ];

    protected $hidden = ['access_code'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_minor' => 'integer',
            'provider_metadata' => 'array',
            'paid_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function savedCard(): BelongsTo
    {
        return $this->belongsTo(SavedCard::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_payment_id');
    }

    public function retries(): HasMany
    {
        return $this->hasMany(self::class, 'parent_payment_id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }
}
