<?php

namespace App\Policies;

use App\Models\Affiliate\AffiliateCommission;
use App\Models\User;

class AffiliateCommissionPolicy
{
    public function before(User $user): ?bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return null;
    }

    /** Staff can view commissions; affiliates can view their own. */
    public function viewAny(User $user): bool
    {
        return $user->isStaff() || $user->hasRole('affiliate');
    }

    public function view(User $user, AffiliateCommission $commission): bool
    {
        if ($user->isStaff()) {
            return true;
        }

        // Affiliate can only see their own commissions.
        return $user->affiliate?->id === $commission->affiliate_id;
    }

    /** Only admins can change payment status (handled via before()). */
    public function update(User $user, AffiliateCommission $commission): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function delete(User $user, AffiliateCommission $commission): bool
    {
        return false;
    }

    public function restore(User $user, AffiliateCommission $commission): bool
    {
        return false;
    }

    public function forceDelete(User $user, AffiliateCommission $commission): bool
    {
        return false;
    }
}
