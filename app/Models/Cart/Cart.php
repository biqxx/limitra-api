<?php

namespace App\Models\Cart;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cart extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'currency',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'string',
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
