<?php

namespace App\Models\Payment;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedCard extends Model
{
    protected $fillable = [
        'user_id',
        'provider',
        'provider_customer_code',
        'authorization_code',
        'signature',
        'brand',
        'last4',
        'expiry_month',
        'expiry_year',
        'cardholder_name',
        'reusable',
        'is_default',
    ];

    protected $hidden = [
        'authorization_code',
        'provider_customer_code',
        'signature',
    ];

    protected function casts(): array
    {
        return [
            'authorization_code' => 'encrypted',
            'expiry_month' => 'integer',
            'expiry_year' => 'integer',
            'reusable' => 'boolean',
            'is_default' => 'boolean',
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Make this card the user's default, clearing any previous default.
     */
    public function setAsDefault(): void
    {
        self::where('user_id', $this->user_id)
            ->where('id', '!=', $this->id)
            ->update(['is_default' => false]);

        $this->update(['is_default' => true]);
    }

    /**
     * Returns true if the card is expired relative to the given date.
     */
    public function isExpired(?\DateTimeInterface $date = null): bool
    {
        $date = $date ?? now();

        return $this->expiry_year < $date->format('Y')
            || ($this->expiry_year == $date->format('Y') && $this->expiry_month < $date->format('n'));
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
