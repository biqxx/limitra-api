<?php

namespace App\Models\Affiliate;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AffiliateClick extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'affiliate_id',
        'affiliate_link_id',
        'destination_url',
        'ip_hash',
        'user_agent',
        'is_bot',
        'is_self_referral',
        'converted',
        'converted_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'is_bot' => 'boolean',
            'is_self_referral' => 'boolean',
            'converted' => 'boolean',
            'converted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function affiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class);
    }

    public function link(): BelongsTo
    {
        return $this->belongsTo(AffiliateLink::class, 'affiliate_link_id');
    }
}
