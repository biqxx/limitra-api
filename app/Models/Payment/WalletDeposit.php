<?php

namespace App\Models\Payment;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WalletDeposit extends Model
{
    protected $fillable = [
        'user_id',
        'account_id',
        'reference',
        'amount_minor',
        'currency',
        'status',
        'method',
        'authorization_url',
        'access_code',
        'provider_reference',
        'paid_at',
        'metadata',
        'idempotency_key',
        'request_hash',
        'expires_at',
        'failure_reason',
    ];

    protected $hidden = [
        'access_code',
        'metadata',
        'request_hash',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function isCompleted(): bool
    {
        return $this->status === 'completed';
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' || ($this->expires_at && $this->expires_at->isPast() && $this->status === 'pending');
    }
}
