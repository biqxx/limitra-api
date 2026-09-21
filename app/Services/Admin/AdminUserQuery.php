<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Models\User\Profile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class AdminUserQuery
{
    /**
     * @param  array{
     *     q?: string,
     *     role?: string,
     *     status?: string,
     *     from?: string,
     *     to?: string,
     *     sort?: string,
     *     direction?: string,
     *     page?: int,
     *     per_page?: int
     * }  $filters
     * @return LengthAwarePaginator<int, User>
     */
    public function execute(array $filters): LengthAwarePaginator
    {
        $sortColumn = $this->sortColumn($filters['sort'] ?? 'created_at');
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $query = User::withTrashed()
            ->select([
                'users.id',
                'users.username',
                'users.email',
                'users.role',
                'users.status',
                'users.suspended_at',
                'users.suspended_until',
                'users.suspension_reason',
                'users.suspended_by',
                'users.deactivated_at',
                'users.deactivation_reason',
                'users.deactivated_by',
                'users.email_verified_at',
                'users.created_at',
                'users.updated_at',
                'users.deleted_at',
            ])
            ->with([
                'profile:id,user_id,first_name,middle_name,last_name,avatar,phone,birthday,gender,subscribe_to_newsletter,created_at,updated_at',
                'roles.permissions',
            ]);

        $this->applySearch($query, $filters['q'] ?? null);
        $this->applyRole($query, $filters['role'] ?? null);

        $query
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query
                ->where('users.status', $status))
            ->when($filters['from'] ?? null, fn (Builder $query, string $date) => $query
                ->whereDate('users.created_at', '>=', $date))
            ->when($filters['to'] ?? null, fn (Builder $query, string $date) => $query
                ->whereDate('users.created_at', '<=', $date))
            ->orderBy($sortColumn, $direction)
            ->orderBy('users.id', $direction);

        return $query->paginate(
            $filters['per_page'] ?? 20,
            ['*'],
            'page',
            $filters['page'] ?? null,
        );
    }

    private function applySearch(Builder $query, ?string $search): void
    {
        if ($search === null || $search === '') {
            return;
        }

        $terms = preg_split('/\s+/u', $search, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($terms as $term) {
            $pattern = "%{$term}%";

            $query->where(function (Builder $query) use ($pattern): void {
                $query
                    ->whereLike('users.username', $pattern)
                    ->orWhereLike('users.email', $pattern)
                    ->orWhereIn('users.id', Profile::query()
                        ->select('profiles.user_id')
                        ->where(function (Builder $profiles) use ($pattern): void {
                            $profiles
                                ->whereLike('profiles.first_name', $pattern)
                                ->orWhereLike('profiles.middle_name', $pattern)
                                ->orWhereLike('profiles.last_name', $pattern)
                                ->orWhereLike('profiles.phone', $pattern);
                        }));
            });
        }
    }

    private function applyRole(Builder $query, ?string $role): void
    {
        if ($role === null || $role === '') {
            return;
        }

        $query->where(function (Builder $query) use ($role): void {
            $query
                ->where('users.role', $role)
                ->orWhereIn('users.id', DB::table('role_user')
                    ->join('roles', 'roles.id', '=', 'role_user.role_id')
                    ->where('roles.name', $role)
                    ->select('role_user.user_id'));
        });
    }

    private function sortColumn(string $sort): string
    {
        return match ($sort) {
            'updated_at' => 'users.updated_at',
            'username' => 'users.username',
            'email' => 'users.email',
            'role' => 'users.role',
            'status' => 'users.status',
            default => 'users.created_at',
        };
    }
}
