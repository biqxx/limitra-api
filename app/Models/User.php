<?php

namespace App\Models;

use App\Models\Address\Address;
use App\Models\Affiliate\Affiliate;
use App\Models\Cart\Cart;
use App\Models\Cart\CartItem;
use App\Models\Cart\Favorite;
use App\Models\Order\Order;
use App\Models\Order\ReturnRequest;
use App\Models\Payment\Account;
use App\Models\Payment\SavedCard;
use App\Models\Payment\WalletTransaction;
use App\Models\Product\Review;
use App\Models\Referral\CustomerReferral;
use App\Models\Referral\CustomerReferralCode;
use App\Models\Referral\CustomerReferralInvitation;
use App\Models\Referral\CustomerReferralShareEvent;
use App\Models\Support\SupportTicket;
use App\Models\User\AuthSession;
use App\Models\User\Profile;
use App\Models\User\UserPreference;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $fillable = [
        'username',
        'email',
        'pending_email',
        'email_change_otp',
        'email_change_expires_at',
        'email_change_attempts',
        'password',
        'role',
        'referred_by',
        'email_verification_otp',
        'email_change_otp',
        'email_verification_expires_at',
        'email_verification_sent_at',
        'email_verification_attempts',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'email_verification_otp',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
            'email_verification_sent_at' => 'datetime',
            'email_change_expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // ── JWTSubject ────────────────────────────────────────────────────────────

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [
            'role' => $this->role,
        ];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function isStaff(): bool
    {
        return in_array($this->role, ['admin', 'staff']);
    }

    public function hasRole(string $role): bool
    {
        return $this->role === $role;
    }

    // ── Relationships ─────────────────────────────────────────────────────────

    /** The affiliate account linked to this user (role = affiliate). */
    public function affiliate(): HasOne
    {
        return $this->hasOne(Affiliate::class);
    }

    /** The affiliate who recruited this user. */
    public function referredByAffiliate(): BelongsTo
    {
        return $this->belongsTo(Affiliate::class, 'referred_by');
    }

    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    public function preference(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function cartItems(): HasManyThrough
    {
        return $this->hasManyThrough(CartItem::class, Cart::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(Favorite::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function savedCards(): HasMany
    {
        return $this->hasMany(SavedCard::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function returnRequests(): HasMany
    {
        return $this->hasMany(ReturnRequest::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function assignedSupportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class, 'assigned_to');
    }

    public function customerReferralCode(): HasOne
    {
        return $this->hasOne(CustomerReferralCode::class);
    }

    public function customerReferrals(): HasMany
    {
        return $this->hasMany(CustomerReferral::class, 'referrer_id');
    }

    public function customerReferrerRecord(): HasOne
    {
        return $this->hasOne(CustomerReferral::class, 'referred_user_id');
    }

    public function customerReferralInvitations(): HasMany
    {
        return $this->hasMany(CustomerReferralInvitation::class, 'referrer_id');
    }

    public function customerReferralShareEvents(): HasMany
    {
        return $this->hasMany(CustomerReferralShareEvent::class);
    }

    public function authSessions(): HasMany
    {
        return $this->hasMany(AuthSession::class);
    }

    public function account(): HasOne
    {
        return $this->hasOne(Account::class);
    }

    public function walletTransactions(): HasManyThrough
    {
        return $this->hasManyThrough(WalletTransaction::class, Account::class);
    }

    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    public function avatar(): HasOne
    {
        return $this->hasOne(Image::class, 'imageable_id')
            ->where('imageable_type', self::class)
            ->where('type', 'user_avatar');
    }
}
