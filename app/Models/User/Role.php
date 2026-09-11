<?php

namespace App\Models\User;

use App\Models\User;
use Database\Factories\User\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'display_name',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'is_super_admin' => 'boolean',
        ];
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot(['assigned_by', 'is_primary', 'assigned_at'])
            ->withTimestamps();
    }

    public function staffInvitations(): HasMany
    {
        return $this->hasMany(StaffInvitation::class);
    }
}
