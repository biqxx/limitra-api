<?php

namespace App\Policies;

use App\Models\Affiliate\Affiliate;
use App\Models\User;

class AffiliatePolicy
{
    public function before(User $user): ?bool
    {
        // Admins bypass all checks.
        if ($user->isAdmin()) {
            return true;
        }

        return null;
    }

    /** Staff and affiliates can list affiliates. */
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->hasRole('affiliate');
    }

    /** An affiliate can view their own record; staff can view any. */
    public function view(User $user, Affiliate $affiliate): bool
    {
        return $user->isStaff() || $user->id === $affiliate->user_id;
    }

    /** Only admins can create (handled via before()). */
    public function create(User $user): bool
    {
        return false;
    }

    /** Only admins can update commission rates / status (handled via before()). */
    public function update(User $user, Affiliate $affiliate): bool
    {
        return false;
    }

    public function delete(User $user, Affiliate $affiliate): bool
    {
        return false;
    }

    public function restore(User $user, Affiliate $affiliate): bool
    {
        return false;
    }

    public function forceDelete(User $user, Affiliate $affiliate): bool
    {
        return false;
    }
}
