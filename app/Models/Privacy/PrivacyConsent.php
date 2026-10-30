<?php

namespace App\Models\Privacy;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PrivacyConsent extends Model
{
    protected $fillable = [
        'user_id',
        'guest_token',
        'policy_version',
        'essential',
        'analytics',
        'marketing',
        'third_party_sharing',
        'personalized_ads',
        'source',
        'ip_hash',
        'user_agent',
        'consented_at',
    ];

    protected function casts(): array
    {
        return [
            'essential' => 'boolean',
            'analytics' => 'boolean',
            'marketing' => 'boolean',
            'third_party_sharing' => 'boolean',
            'personalized_ads' => 'boolean',
            'consented_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
