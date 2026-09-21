<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\AccessControl\ListUsersRequest;
use App\Http\Requests\Admin\AccessControl\StoreStaffInvitationRequest;
use App\Http\Requests\Admin\AccessControl\ViewUserRequest;
use App\Http\Resources\AdminUserDetailResource;
use App\Http\Resources\AdminUserResource;
use App\Http\Resources\StaffInvitationResource;
use App\Models\User;
use App\Services\Admin\AdminUserDetailService;
use App\Services\Admin\AdminUserQuery;
use App\Services\Auth\StaffInvitationService;
use Illuminate\Http\JsonResponse;

class UserDirectoryController extends BaseController
{
    public function __construct(
        private readonly AdminUserQuery $users,
        private readonly AdminUserDetailService $userDetails,
        private readonly StaffInvitationService $invitations,
    ) {}

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

    public function store(StoreStaffInvitationRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('api');
        $invitation = $this->invitations->create($actor, $request->validated());

        return $this->success(
            new StaffInvitationResource($invitation),
            'User invitation queued.',
            201,
        );
    }

    public function show(ViewUserRequest $request, User $user): JsonResponse
    {
        return $this->success(new AdminUserDetailResource(
            $this->userDetails->get($user),
        ));
    }
}
