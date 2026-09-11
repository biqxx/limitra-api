<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\PermissionResource;
use App\Models\User\Permission;
use Illuminate\Http\JsonResponse;

class PermissionController extends BaseController
{
    public function index(): JsonResponse
    {
        $permissions = Permission::query()->orderBy('domain')->orderBy('name')->get();

        return $this->success(PermissionResource::collection($permissions));
    }
}
