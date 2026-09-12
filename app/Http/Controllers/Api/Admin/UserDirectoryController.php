<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\AccessControl\ListUsersRequest;
use App\Http\Resources\AdminUserResource;
use App\Services\Admin\AdminUserQuery;
use Illuminate\Http\JsonResponse;

class UserDirectoryController extends BaseController
{
    public function __construct(private readonly AdminUserQuery $users) {}

    public function index(ListUsersRequest $request): JsonResponse
    {
        $page = $this->users->execute($request->validated());

        return $this->success([
            'items' => AdminUserResource::collection($page->getCollection()),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }
}
