<?php

namespace App\Models\Affiliate;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Affiliate extends Model
{
    protected $fillable = [
        'user_id',
        'slug',
        'code',
        'commission_rate',
        'status',
        'payout_details',
        'total_earnings',
        'total_paid',
    ];

    protected function casts(): array
    {
        return [
            'payout_details' => 'array',
            'commission_rate' => 'decimal:2',
            'total_earnings' => 'decimal:2',
            'total_paid' => 'decimal:2',
        ];
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Users who signed up via this affiliate's referral link. */
    public function referrals(): HasMany
    {
        return $this->hasMany(AffiliateReferral::class);
    }

    /** All sales (direct + indirect) attributed to this affiliate. */
    public function sales(): HasMany
    {
        return $this->hasMany(AffiliateSale::class);
    }

    /** Commission records for this affiliate. */
    public function commissions(): HasMany
    {
        return $this->hasMany(AffiliateCommission::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** Balance not yet paid out: total_earnings minus total_paid. */
    public function getPendingBalanceAttribute(): string
    {
        return number_format((float) $this->total_earnings - (float) $this->total_paid, 2, '.', '');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /** Generate a cryptographically safe unique code. */
    public static function generateCode(): string
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (static::where('code', $code)->exists());

        return $code;
    }

    /** Derive slug from a display name, ensuring uniqueness. */
    public static function generateSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base;
        $i = 1;

        while (static::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$i}";
            $i++;
        }

        return $slug;
    }
}
