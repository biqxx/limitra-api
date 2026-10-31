<?php

namespace App\Models\Affiliate;

use App\Models\User;
use App\Services\Affiliate\AffiliateLedgerService;
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
        'current_tier',
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

    public function links(): HasMany
    {
        return $this->hasMany(AffiliateLink::class);
    }

    public function payoutMethods(): HasMany
    {
        return $this->hasMany(AffiliatePayoutMethod::class);
    }

    public function withdrawals(): HasMany
    {
        return $this->hasMany(AffiliateWithdrawal::class);
    }

    public function promoCodes(): HasMany
    {
        return $this->hasMany(AffiliatePromoCode::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(AffiliateLedgerEntry::class);
    }

    public function clicks(): HasMany
    {
        return $this->hasMany(AffiliateClick::class);
    }

    public function tierHistories(): HasMany
    {
        return $this->hasMany(AffiliateTierHistory::class);
    }

    // ── Authoritative Financial Balances ──────────────────────────────────────

    /**
     * Authoritative available balance:
     * Settled earnings minus pending holds minus paid withdrawals.
     */
    public function getAuthoritativeAvailableBalance(?int $excludeWithdrawalId = null): float
    {
        return app(AffiliateLedgerService::class)->calculateAuthoritativeAvailableBalance($this, $excludeWithdrawalId);
    }

    /** Total currently reserved in active withdrawals (pending, approved, processing). */
    public function getAuthoritativeHeldBalance(): float
    {
        return (float) $this->withdrawals()->whereIn('status', ['pending', 'approved', 'processing'])->sum('amount');
    }

    /** Total authoritative paid out. */
    public function getAuthoritativeTotalPaid(): float
    {
        return (float) $this->withdrawals()->where('status', 'paid')->sum('amount');
    }

    /** Backward compatibility helper for pending/available balance. */
    public function getPendingBalanceAttribute(): string
    {
        return number_format($this->getAuthoritativeAvailableBalance(), 2, '.', '');
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
