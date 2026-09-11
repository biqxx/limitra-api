<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\AccessControl\UpdateLegacyRoleRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Auth\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UserAccessController extends BaseController
{
    public function __construct(private readonly AccessControlService $accessControl) {}

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
}
