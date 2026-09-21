<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Admin\AccessControl\ListStaffInvitationsRequest;
use App\Http\Requests\Admin\AccessControl\ResendStaffInvitationRequest;
use App\Http\Requests\Admin\AccessControl\RevokeStaffInvitationRequest;
use App\Http\Requests\Admin\AccessControl\StoreStaffInvitationRequest;
use App\Http\Resources\StaffInvitationResource;
use App\Models\User;
use App\Models\User\StaffInvitation;
use App\Services\Auth\StaffInvitationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class StaffInvitationController extends BaseController
{
    public function __construct(private readonly StaffInvitationService $invitations) {}

    public function index(ListStaffInvitationsRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $invitations = StaffInvitation::query()
            ->with('role:id,name,display_name')
            ->when($validated['q'] ?? null, fn (Builder $query, string $search) => $query
                ->where(fn (Builder $query) => $query
                    ->where('email_masked', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")))
            ->when($validated['status'] ?? null, fn (Builder $query, string $status) => $query
                ->where('status', $status))
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 20);

        return $this->success([
            'items' => StaffInvitationResource::collection($invitations->getCollection()),
            'pagination' => [
                'current_page' => $invitations->currentPage(),
                'per_page' => $invitations->perPage(),
                'total' => $invitations->total(),
                'last_page' => $invitations->lastPage(),
            ],
        ]);
    }

    public function store(StoreStaffInvitationRequest $request): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user('api');
        $invitation = $this->invitations->create($actor, $request->validated());

        return $this->success(new StaffInvitationResource($invitation), 'Staff invitation queued.', 201);
    }

    public function resend(
        ResendStaffInvitationRequest $request,
        StaffInvitation $staffInvitation,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user('api');
        $invitation = $this->invitations->resend($actor, $staffInvitation);

        return $this->success(new StaffInvitationResource($invitation), 'Staff invitation queued.', 202);
    }

    public function destroy(
        RevokeStaffInvitationRequest $request,
        StaffInvitation $staffInvitation,
    ): Response {
        /** @var User $actor */
        $actor = $request->user('api');
        $this->invitations->revoke($actor, $staffInvitation, $request->validated('reason'));

        return response()->noContent();
    }
}
