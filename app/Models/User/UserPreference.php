<?php

namespace App\Models\User;

use App\Models\User as UserModel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPreference extends Model
{
    protected $attributes = [
        'theme' => 'light',
        'locale' => 'en',
        'currency' => 'NGN',
        'notifications_enabled' => true,
        'email_notifications' => true,
        'sms_notifications' => false,
        'push_notifications' => false,
        'in_app_notifications' => true,
        'marketing_emails' => false,
        'marketing_sms' => false,
        'marketing_push' => false,
        'marketing_in_app' => false,
    ];

    protected $fillable = [
        'user_id',
        'theme',
        'locale',
        'currency',
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
