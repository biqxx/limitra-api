<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdempotencyKey extends Model
{
    protected $fillable = [
        'user_id',
        'operation',
        'key',
        'request_hash',
        'resource_type',
        'resource_id',
        'response_status',
    ];

    protected function casts(): array
    {
        return [
            'resource_id' => 'integer',
            'response_status' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
