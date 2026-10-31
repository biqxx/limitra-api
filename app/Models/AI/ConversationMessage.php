<?php

namespace App\Models\AI;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationMessage extends Model
{
    protected $fillable = [
        'conversation_id',
        'role',
        'content',
        'tool_calls',
        'tool_results',
        'metadata',
        'client_message_id',
        'request_message_id',
        'latency_ms',
        'tokens',
    ];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'tool_results' => 'array',
            'metadata' => 'array',
            'tokens' => 'array',
            'latency_ms' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function requestMessage(): BelongsTo
    {
        return $this->belongsTo(self::class, 'request_message_id');
    }

    public function getAttachmentsAttribute(): ?array
    {
        return $this->metadata['attachments'] ?? $this->metadata['images'] ?? null;
    }
}
