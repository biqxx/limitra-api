<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use LogicException;

class WalletTransaction extends Model
{
    protected $fillable = [
        'reference',
        'account_id',
        'type',
        'balance_type',
        'direction',
        'amount_minor',
        'balance_after_minor',
        'currency',
        'status',
        'unique_key',
        'description',
        'source_type',
        'source_id',
        'reverses_transaction_id',
        'metadata',
    ];

    protected $attributes = ['status' => 'posted'];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'balance_after_minor' => 'integer',
            'source_id' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Posted wallet transactions are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Posted wallet transactions cannot be deleted.'));
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function reversesTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_transaction_id');
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reverses_transaction_id');
    }
}
