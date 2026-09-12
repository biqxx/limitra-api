<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\AccessControl\RestoreUserRequest;
use App\Http\Requests\Admin\AccessControl\SuspendUserRequest;
use App\Http\Requests\Admin\AccessControl\UpdateLegacyRoleRequest;
use App\Http\Requests\Admin\AccessControl\UpdateUserStatusRequest;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AccessControlService;
use App\Services\Auth\UserStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UserAccessController extends BaseController
{
    public function __construct(
        private readonly AccessControlService $accessControl,
        private readonly UserStatusService $statuses,
    ) {}

    public function updateRole(UpdateLegacyRoleRequest $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('api');
        $user = $this->accessControl->updateLegacyRole($actor, $user, $request->string('role')->toString());
        $user->load(['profile', 'roles.permissions']);

        return $this->success(new UserResource($user), 'User role updated.');
    }

    public function destroy(Request $request, User $user): Response
    {
        /** @var User $actor */
        $actor = $request->user('api');
        $this->accessControl->deleteUser($actor, $user);

        return response()->noContent();
    }

    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('api');
        $status = UserStatus::from($request->validated('status'));
        $user = $status === UserStatus::Suspended
            ? $this->statuses->suspend(
                $actor,
                $user,
                $request->validated('reason'),
                $request->validated('suspended_until')
                    ? CarbonImmutable::parse($request->validated('suspended_until'))
                    : null,
            )
            : $this->statuses->restore($actor, $user, $request->validated('reason'));

        return $this->success(new AdminUserResource($user), 'User status updated.');
    }

    public function suspend(SuspendUserRequest $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('api');
        $until = $request->validated('suspended_until');
        $user = $this->statuses->suspend(
            $actor,
            $user,
            $request->validated('reason'),
            $until ? CarbonImmutable::parse($until) : null,
        );

        return $this->success(new AdminUserResource($user), 'User suspended.');
    }

    public function restore(RestoreUserRequest $request, User $user): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('api');
        $user = $this->statuses->restore($actor, $user, $request->validated('note'));

        return $this->success(new AdminUserResource($user), 'User restored.');
    }
}
