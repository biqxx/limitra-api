<?php

namespace App\Models\Cart;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    protected $hidden = ['guest_token_hash'];

    protected $fillable = [
        'user_id',
        'guest_token_hash',
        'guest_expires_at',
        'merged_at',
        'status',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'string',
            'guest_expires_at' => 'datetime',
            'merged_at' => 'datetime',
        ];
    }

    // ── Scopes ──────────────────────────────────────────────────────────────

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Return the user's single active cart, creating one if it doesn't exist.
     */
    public static function activeForUser(int $userId): self
    {
        return self::firstOrCreate(
            ['user_id' => $userId, 'status' => 'active']
        );
    }

    public static function activeForGuestToken(string $token): ?self
    {
        return self::where('guest_token_hash', hash('sha256', $token))
            ->where('status', 'active')
            ->whereNull('merged_at')
            ->where('guest_expires_at', '>', now())
            ->first();
    }

    /**
     * Mark the cart as checked-out and delete all its items.
     */
    public function checkout(): void
    {
        $this->items()->delete();
        $this->update(['status' => 'checked_out']);
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CartItem::class);
    }
}
