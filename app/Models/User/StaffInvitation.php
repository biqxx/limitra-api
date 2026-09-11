<?php

namespace App\Models\User;

use App\Enums\StaffInvitationStatus;
use App\Models\User;
use Database\Factories\User\StaffInvitationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffInvitation extends Model
{
    /** @use HasFactory<StaffInvitationFactory> */
    use HasFactory;

    protected $fillable = [
        'public_id',
        'email_hash',
        'email_ciphertext',
        'email_masked',
        'name',
        'role_id',
        'role_name_snapshot',
        'invited_by',
        'status',
        'token_hash',
        'token_ciphertext',
        'delivery_version',
        'expires_at',
        'sent_at',
        'accepted_at',
        'revoked_at',
        'failed_at',
        'accepted_user_id',
        'failure_code',
    ];

    protected $hidden = [
        'email_hash',
        'email_ciphertext',
        'token_hash',
        'token_ciphertext',
        'failure_code',
    ];

    protected function casts(): array
    {
        return [
            'email_ciphertext' => 'encrypted',
            'token_ciphertext' => 'encrypted',
            'status' => StaffInvitationStatus::class,
            'delivery_version' => 'integer',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }
}
