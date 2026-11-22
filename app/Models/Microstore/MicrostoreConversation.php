<?php

namespace App\Models\Microstore;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MicrostoreConversation extends Model
{
    protected $fillable = [
        'microstore_id',
        'customer_id',
        'status',
        'customer_unread_count',
        'owner_unread_count',
        'last_message_at',
    ];

    protected function casts(): array
    {
        return [
            'customer_unread_count' => 'integer',
            'owner_unread_count' => 'integer',
            'last_message_at' => 'datetime',
        ];
    }

    public function microstore(): BelongsTo
    {
        return $this->belongsTo(Microstore::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MicrostoreMessage::class, 'conversation_id')->orderBy('id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(MicrostoreMessage::class, 'conversation_id')->latestOfMany();
    }
}
