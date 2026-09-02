<?php

namespace App\Models\Notification;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationEventSetting extends Model
{
    protected $primaryKey = 'event';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'event',
        'label',
        'category',
        'available_channels',
        'default_channels',
        'required_channels',
    ];

    protected function casts(): array
    {
        return [
            'available_channels' => 'array',
            'default_channels' => 'array',
            'required_channels' => 'array',
        ];
    }

    public function preferences(): HasMany
    {
        return $this->hasMany(NotificationPreference::class, 'event', 'event');
    }
}
