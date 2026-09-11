<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\AccessControl\StoreRoleRequest;
use App\Http\Requests\Admin\AccessControl\UpdateRoleRequest;
use App\Http\Resources\RoleResource;
use App\Models\User;
use App\Models\User\Role;
use App\Services\Auth\AccessControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class RoleController extends BaseController
{
    public function __construct(private readonly AccessControlService $accessControl) {}

    public function index(): JsonResponse
    {
        $roles = Role::query()
            ->with(['permissions' => fn ($query) => $query->orderBy('name')])
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        return $this->success(RoleResource::collection($roles));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('api');
        $role = $this->accessControl->createRole($actor, $request->validated());

        return $this->success(new RoleResource($role), 'Role created.', 201);
    }

    public function show(Role $role): JsonResponse
    {
        $role->load(['permissions' => fn ($query) => $query->orderBy('name')])->loadCount('users');

        return $this->success(new RoleResource($role));
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('api');
        $role = $this->accessControl->updateRole($actor, $role, $request->validated());

        return $this->success(new RoleResource($role), 'Role updated.');
    }

    public function destroy(Request $request, Role $role): Response
    {
        /** @var User $actor */
        $actor = $request->user('api');
        $this->accessControl->deleteRole($actor, $role);

        return response()->noContent();
    }
}
