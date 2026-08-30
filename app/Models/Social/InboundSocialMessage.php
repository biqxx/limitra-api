<?php

namespace App\Models\Social;

use App\Models\AI\Conversation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InboundSocialMessage extends Model
{
    protected $fillable = [
        'platform',
        'provider_message_id',
        'platform_sender_id',
        'platform_recipient_id',
        'username',
        'message',
        'reply',
        'occurred_at',
        'status',
        'attempts',
        'processing_token',
        'processing_started_at',
        'processed_at',
        'conversation_id',
        'last_error',
    ];

    protected function casts(): array
    {
        return [
            'message' => 'encrypted',
            'reply' => 'encrypted',
            'occurred_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'attempts' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
