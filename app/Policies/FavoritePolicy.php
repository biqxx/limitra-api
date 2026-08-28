<?php

namespace App\Policies;

use App\Models\Cart\Favorite;
use App\Models\User;

class FavoritePolicy
{
    public function view(User $user, Favorite $favorite): bool
    {
        return $user->id === $favorite->user_id;
    }

    public function delete(User $user, Favorite $favorite): bool
    {
        return $user->id === $favorite->user_id;
    }
}
