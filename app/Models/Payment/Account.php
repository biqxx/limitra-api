<?php

namespace App\Models\Payment;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    protected $fillable = [
        'user_id',
        'currency',
    ];

    protected $attributes = [
        'cash_balance_minor' => 0,
        'lim_cash_balance_minor' => 0,
        'currency' => 'NGN',
    ];

    protected function casts(): array
    {
        return [
            'cash_balance_minor' => 'integer',
            'lim_cash_balance_minor' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class)->latest('id');
    }
}
