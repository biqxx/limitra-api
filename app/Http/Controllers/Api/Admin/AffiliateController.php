<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\AffiliateCommissionResource;
use App\Http\Resources\AffiliateResource;
use App\Models\Affiliate\Affiliate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

/**
 * Admin/Staff management of affiliate accounts.
 *
 * Route middleware:
 *   GET  routes → role:admin,staff
 *   POST/PATCH  → role:admin
 */
class AffiliateController extends BaseController
{
    #[OA\Get(
        path: '/admin/affiliates',
        tags: ['Admin - Affiliates'],
        summary: 'List affiliates (admin/staff)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'active', 'suspended'])),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated affiliate list', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'status' => ['sometimes', 'in:pending,active,suspended'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $affiliates = Affiliate::with('user.profile')
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->search, fn ($q) => $q->whereHas('user', fn ($u) => $u->where('username', 'like', "%{$request->search}%")
                ->orWhere('email', 'like', "%{$request->search}%")
            ))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => AffiliateResource::collection($affiliates->getCollection()),
            'pagination' => [
                'current_page' => $affiliates->currentPage(),
                'last_page' => $affiliates->lastPage(),
                'per_page' => $affiliates->perPage(),
                'total' => $affiliates->total(),
                'from' => $affiliates->firstItem(),
                'to' => $affiliates->lastItem(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/admin/affiliates',
        tags: ['Admin - Affiliates'],
        summary: 'Create affiliate (admin)',
        security: [['bearerAuth' => []]],
        description: 'Promotes an existing user to affiliate status and generates their referral code.',
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['user_id'],
                properties: [
                    new OA\Property(property: 'user_id', type: 'integer'),
                    new OA\Property(property: 'commission_rate', type: 'number', format: 'float', minimum: 0, maximum: 100, nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Affiliate created', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'User already an affiliate', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id', Rule::unique('affiliates', 'user_id')],
            'commission_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        // Ensure the user has the affiliate role.
        $user = User::findOrFail($data['user_id']);
        $user->update(['role' => 'affiliate']);

        $affiliate = Affiliate::create([
            'user_id' => $data['user_id'],
            'slug' => Affiliate::generateSlug($user->username),
            'code' => Affiliate::generateCode(),
            'commission_rate' => $data['commission_rate'] ?? 5.00,
            'status' => 'active',
        ]);

        return $this->success(
            new AffiliateResource($affiliate->load('user.profile')),
            'Affiliate account created.',
            201
        );
    }

    #[OA\Get(
        path: '/admin/affiliates/{affiliate}',
        tags: ['Admin - Affiliates'],
        summary: 'Get affiliate (admin/staff)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'affiliate', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Affiliate details', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function show(Affiliate $affiliate): JsonResponse
    {
        return $this->success(
            new AffiliateResource($affiliate->load('user.profile'))
        );
    }

    #[OA\Patch(
        path: '/admin/affiliates/{affiliate}',
        tags: ['Admin - Affiliates'],
        summary: 'Update affiliate (admin)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'affiliate', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'commission_rate', type: 'number', format: 'float'),
                    new OA\Property(property: 'status', type: 'string', enum: ['pending', 'active', 'suspended']),
                    new OA\Property(
                        property: 'payout_details',
                        type: 'object',
                        properties: [
                            new OA\Property(property: 'bank_name', type: 'string'),
                            new OA\Property(property: 'account_name', type: 'string'),
                            new OA\Property(property: 'account_number', type: 'string'),
                            new OA\Property(property: 'routing_number', type: 'string', nullable: true),
                            new OA\Property(property: 'notes', type: 'string', nullable: true),
                        ]
                    ),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Affiliate updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function update(Request $request, Affiliate $affiliate): JsonResponse
    {
        $data = $request->validate([
            'commission_rate' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'status' => ['sometimes', Rule::in(['pending', 'active', 'suspended'])],
            'payout_details' => ['sometimes', 'nullable', 'array'],
            'payout_details.bank_name' => ['sometimes', 'string'],
            'payout_details.account_name' => ['sometimes', 'string'],
            'payout_details.account_number' => ['sometimes', 'string'],
            'payout_details.routing_number' => ['sometimes', 'nullable', 'string'],
            'payout_details.notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $affiliate->update($data);

        return $this->success(
            new AffiliateResource($affiliate->fresh()->load('user.profile')),
            'Affiliate updated.'
        );
    }

    #[OA\Get(
        path: '/admin/affiliates/{affiliate}/commissions',
        tags: ['Admin - Affiliates'],
        summary: 'Get affiliate commissions (admin/staff)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'affiliate', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'payment_status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'paid', 'rejected'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated commission list', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function commissions(Request $request, Affiliate $affiliate): JsonResponse
    {
        $request->validate([
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date', 'after_or_equal:from_date'],
            'payment_status' => ['sometimes', 'in:pending,approved,paid,rejected'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $commissions = $affiliate->commissions()
            ->with('sale.product')
            ->when($request->payment_status, fn ($q) => $q->where('payment_status', $request->payment_status))
            ->when($request->from_date, fn ($q) => $q->whereDate('created_at', '>=', $request->from_date))
            ->when($request->to_date, fn ($q) => $q->whereDate('created_at', '<=', $request->to_date))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => AffiliateCommissionResource::collection($commissions->getCollection()),
            'pagination' => [
                'current_page' => $commissions->currentPage(),
                'last_page' => $commissions->lastPage(),
                'per_page' => $commissions->perPage(),
                'total' => $commissions->total(),
                'from' => $commissions->firstItem(),
                'to' => $commissions->lastItem(),
            ],
        ]);
    }
}
