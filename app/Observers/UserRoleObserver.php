<?php

namespace App\Observers;

use App\Models\User;
use App\Services\Auth\LegacyRoleSynchronizer;

class UserRoleObserver
{
    public function __construct(private readonly LegacyRoleSynchronizer $synchronizer) {}

    public function created(User $user): void
    {
        $this->synchronizer->synchronize($user);
    }

    public function updated(User $user): void
    {
        if ($user->wasChanged('role')) {
            $this->synchronizer->synchronize($user);
        }
    }
}
