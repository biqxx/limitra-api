<?php

namespace App\Models\Microstore;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MicrostoreWithdrawal extends Model
{
    protected $fillable = [
        'microstore_id',
        'user_id',
        'amount',
        'currency',
        'status',
        'payout_method',
        'payout_details',
        'reference',
        'notes',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payout_details' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    public function microstore(): BelongsTo
    {
        return $this->belongsTo(Microstore::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
