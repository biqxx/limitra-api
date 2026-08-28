<?php

namespace App\Policies;

use App\Models\Payment\Account;
use App\Models\User;

class AccountPolicy
{
    public function before(User $user): ?bool
    {
        // Only admins can create/delete accounts; staff can view.
        if ($user->isAdmin()) {
            return true;
        }

        return null;
    }

    public function view(User $user, Account $account): bool
    {
        return $user->id === $account->user_id || $user->isStaff();
    }

    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function create(User $user): bool
    {
        return false;
    }

    /** Users cannot update their own account record (balance / currency). */
    public function update(User $user, Account $account): bool
    {
        return false;
    }

    public function deposit(User $user, Account $account): bool
    {
        return $user->id === $account->user_id;
    }

    public function withdraw(User $user, Account $account): bool
    {
        return $user->id === $account->user_id;
    }

    public function delete(User $user, Account $account): bool
    {
        return false;
    }
}
