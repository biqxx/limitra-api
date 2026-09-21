<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Auth\AcceptStaffInvitationRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\StaffInvitationService;
use Illuminate\Http\JsonResponse;

class StaffInvitationAcceptanceController extends BaseController
{
    public function __construct(private readonly StaffInvitationService $invitations) {}

    public function __invoke(AcceptStaffInvitationRequest $request): JsonResponse
    {
        $user = $this->invitations->accept($request->validated());

        return $this->success(
            new UserResource($user),
            'Staff invitation accepted. You may now log in.',
            201,
        );
    }
}
