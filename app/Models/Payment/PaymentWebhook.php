<?php

namespace App\Models\Payment;

use Illuminate\Database\Eloquent\Model;

class PaymentWebhook extends Model
{
    protected $fillable = [
        'provider',
        'payload_hash',
        'event',
        'reference',
        'status',
        'payload',
        'processed_at',
        'error',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'encrypted:array',
            'processed_at' => 'datetime',
        ];
    }
}
