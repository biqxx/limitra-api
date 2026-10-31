<?php

namespace App\Policies;

use App\Models\User;
use App\Models\User\Profile;

class ProfilePolicy
{
    public function before(User $user): ?bool
    {
        if ($user->isStaff()) {
            return true;
        }

        return null;
    }

    public function view(User $user, Profile $profile): bool
    {
        return $user->id === $profile->user_id;
    }

    public function update(User $user, Profile $profile): bool
    {
        return $user->id === $profile->user_id;
    }
}
