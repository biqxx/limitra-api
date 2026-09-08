<?php

namespace App\Models\User;

use App\Models\User as UserModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $fillable = [
        'user_id',
        'theme',
        'language',
        'notifications_enabled',
        'email_notifications',
        'sms_notifications',
        'push_notifications',
        'in_app_notifications',
        'marketing_emails',
        'marketing_sms',
        'marketing_push',
        'marketing_in_app',
    ];

    protected function casts(): array
    {
        return [
            'notifications_enabled' => 'boolean',
            'email_notifications' => 'boolean',
            'sms_notifications' => 'boolean',
            'push_notifications' => 'boolean',
            'in_app_notifications' => 'boolean',
            'marketing_emails' => 'boolean',
            'marketing_sms' => 'boolean',
            'marketing_push' => 'boolean',
            'marketing_in_app' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class);
    }
}
