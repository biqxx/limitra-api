<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\AccessControl\AssignRoleMemberRequest;
use App\Http\Requests\Admin\AccessControl\ListRoleMembersRequest;
use App\Http\Requests\Admin\AccessControl\ReplaceStaffRoleRequest;
use App\Http\Resources\RoleMemberResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Models\User\Role;
use App\Services\Auth\AccessControlService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RoleMemberController extends BaseController
{
    public function __construct(private readonly AccessControlService $accessControl) {}

    public function index(ListRoleMembersRequest $request, Role $role): JsonResponse
    {
        $validated = $request->validated();
        $members = $role->users()
            ->with('profile')
            ->when($validated['q'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $query->where('username', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhereHas('profile', fn (Builder $profileQuery) => $profileQuery
                            ->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('users.id')
            ->paginate($validated['per_page'] ?? 20);

        return $this->success(RoleMemberResource::collection($members)->response()->getData(true));
    }

    public function store(AssignRoleMemberRequest $request, Role $role): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('api');
        $user = User::query()->findOrFail($request->integer('user_id'));
        $this->accessControl->addMember($actor, $role, $user);

        $member = $role->users()->with('profile')->findOrFail($user->getKey());

        return $this->success(new RoleMemberResource($member), 'Role member added.', 201);
    }

    public function destroy(Request $request, Role $role, User $user): Response
    {
        /** @var User $actor */
        $actor = $request->user('api');
        $this->accessControl->removeMember($actor, $role, $user);

        return response()->noContent();
    }

    public function replace(ReplaceStaffRoleRequest $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('api');
        $role = Role::query()->findOrFail($request->integer('role_id'));
        $user = $this->accessControl->replaceStaffRole($actor, $user, $role);
        $user->load(['profile', 'roles.permissions']);

        return $this->success(new UserResource($user), 'Staff role updated.');
    }
}
