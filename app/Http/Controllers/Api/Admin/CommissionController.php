<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\AffiliateCommissionResource;
use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateCommission;
use App\Services\Admin\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use OpenApi\Attributes as OA;

/**
 * Admin/Staff commission management.
 *
 * Route middleware:
 *   GET  routes → role:admin,staff
 *   PATCH / bulk-update → role:admin
 */
class CommissionController extends BaseController
{
    private const ALLOWED_TRANSITIONS = [
        'pending' => ['approved', 'rejected'],
        'approved' => ['paid', 'rejected'],
        'paid' => ['rejected'],
        'rejected' => ['approved'],
    ];

    #[OA\Get(
        path: '/admin/commissions',
        tags: ['Admin - Commissions'],
        summary: 'List commissions (admin/staff)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'affiliate_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'payment_status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'paid', 'rejected'])),
            new OA\Parameter(name: 'from_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'sort', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'])),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated commission list', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'affiliate_id' => ['sometimes', 'integer', 'exists:affiliates,id'],
            'payment_status' => ['sometimes', 'in:pending,approved,paid,rejected'],
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date', 'after_or_equal:from_date'],
            'sort' => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $commissions = AffiliateCommission::with(['affiliate.user', 'sale.product'])
            ->when($request->affiliate_id, fn ($q) => $q->where('affiliate_id', $request->affiliate_id))
            ->when($request->payment_status, fn ($q) => $q->where('payment_status', $request->payment_status))
            ->when($request->from_date, fn ($q) => $q->whereDate('created_at', '>=', $request->from_date))
            ->when($request->to_date, fn ($q) => $q->whereDate('created_at', '<=', $request->to_date))
            ->orderBy('created_at', $request->input('sort', 'desc'))
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

    #[OA\Get(
        path: '/admin/commissions/{commission}',
        tags: ['Admin - Commissions'],
        summary: 'Get commission (admin/staff)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'commission', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Commission details', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function show(AffiliateCommission $commission): JsonResponse
    {
        return $this->success(
            new AffiliateCommissionResource($commission->load(['affiliate.user', 'sale.product']))
        );
    }

    #[OA\Patch(
        path: '/admin/commissions/{commission}',
        tags: ['Admin - Commissions'],
        summary: 'Update commission status (admin)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'commission', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['payment_status'],
                properties: [
                    new OA\Property(property: 'payment_status', type: 'string', enum: ['pending', 'approved', 'paid', 'rejected']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Commission updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function update(Request $request, AffiliateCommission $commission, AuditEventService $auditEvents): JsonResponse
    {
        $data = $request->validate([
            'payment_status' => ['required', Rule::in(['pending', 'approved', 'paid', 'rejected'])],
        ]);

        $newStatus = $data['payment_status'];
        $currentStatus = $commission->payment_status;

        if ($currentStatus === $newStatus) {
            return $this->success(
                new AffiliateCommissionResource($commission->fresh()->load(['affiliate.user', 'sale.product'])),
                'Commission updated.'
            );
        }

        if (! in_array($newStatus, self::ALLOWED_TRANSITIONS[$currentStatus] ?? [], true)) {
            throw ValidationException::withMessages([
                'payment_status' => ["Cannot transition commission from {$currentStatus} to {$newStatus}."],
            ]);
        }

        DB::transaction(function () use ($commission, $currentStatus, $newStatus, $auditEvents, $request): void {
            /** @var AffiliateCommission $lockedCommission */
            $lockedCommission = AffiliateCommission::whereKey($commission->id)->lockForUpdate()->firstOrFail();
            /** @var Affiliate $affiliate */
            $affiliate = Affiliate::whereKey($lockedCommission->affiliate_id)->lockForUpdate()->firstOrFail();

            $paidAt = $lockedCommission->paid_at;
            if ($newStatus === 'paid') {
                $paidAt = now();
                $affiliate->increment('total_paid', $lockedCommission->commission_amount);
            } elseif ($newStatus === 'rejected') {
                if ($currentStatus === 'paid') {
                    $affiliate->decrement('total_paid', $lockedCommission->commission_amount);
                }
                $affiliate->decrement('total_earnings', $lockedCommission->commission_amount);
            } elseif ($currentStatus === 'rejected' && $newStatus === 'approved') {
                $affiliate->increment('total_earnings', $lockedCommission->commission_amount);
            }

            $lockedCommission->update([
                'payment_status' => $newStatus,
                'paid_at' => $paidAt,
            ]);

            $auditEvents->record(
                action: 'commission.status_updated',
                actor: $request->user('api'),
                subject: $lockedCommission,
                before: ['payment_status' => $currentStatus],
                after: ['payment_status' => $newStatus],
                metadata: [
                    'affiliate_id' => $affiliate->id,
                    'commission_amount' => $lockedCommission->commission_amount,
                ],
                request: $request,
            );
        });

        return $this->success(
            new AffiliateCommissionResource($commission->fresh()->load(['affiliate.user', 'sale.product'])),
            'Commission updated.'
        );
    }

    #[OA\Post(
        path: '/admin/commissions/bulk-update',
        tags: ['Admin - Commissions'],
        summary: 'Bulk update commission statuses (admin)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['ids', 'payment_status'],
                properties: [
                    new OA\Property(property: 'ids', type: 'array', items: new OA\Items(type: 'integer'), description: 'Commission IDs to update'),
                    new OA\Property(property: 'payment_status', type: 'string', enum: ['pending', 'approved', 'paid', 'rejected']),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Commissions updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function bulkUpdate(Request $request, AuditEventService $auditEvents): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'exists:affiliate_commissions,id'],
            'payment_status' => ['required', Rule::in(['pending', 'approved', 'paid', 'rejected'])],
        ]);

        $newStatus = $data['payment_status'];

        $updatedCount = DB::transaction(function () use ($data, $newStatus, $auditEvents, $request): int {
            $commissions = AffiliateCommission::whereIn('id', $data['ids'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $count = 0;

            foreach ($commissions as $commission) {
                $currentStatus = $commission->payment_status;
                if ($currentStatus === $newStatus) {
                    continue;
                }

                if (! in_array($newStatus, self::ALLOWED_TRANSITIONS[$currentStatus] ?? [], true)) {
                    throw ValidationException::withMessages([
                        'payment_status' => ["Commission #{$commission->id} cannot transition from {$currentStatus} to {$newStatus}."],
                    ]);
                }

                /** @var Affiliate $affiliate */
                $affiliate = Affiliate::whereKey($commission->affiliate_id)->lockForUpdate()->firstOrFail();

                $paidAt = $commission->paid_at;
                if ($newStatus === 'paid') {
                    $paidAt = now();
                    $affiliate->increment('total_paid', $commission->commission_amount);
                } elseif ($newStatus === 'rejected') {
                    if ($currentStatus === 'paid') {
                        $affiliate->decrement('total_paid', $commission->commission_amount);
                    }
                    $affiliate->decrement('total_earnings', $commission->commission_amount);
                } elseif ($currentStatus === 'rejected' && $newStatus === 'approved') {
                    $affiliate->increment('total_earnings', $commission->commission_amount);
                }

                $commission->update([
                    'payment_status' => $newStatus,
                    'paid_at' => $paidAt,
                ]);

                $auditEvents->record(
                    action: 'commission.status_updated',
                    actor: $request->user('api'),
                    subject: $commission,
                    before: ['payment_status' => $currentStatus],
                    after: ['payment_status' => $newStatus],
                    metadata: [
                        'affiliate_id' => $affiliate->id,
                        'commission_amount' => $commission->commission_amount,
                    ],
                    request: $request,
                );

                $count++;
            }

            return $count;
        });

        return $this->success(['updated' => $updatedCount], "{$updatedCount} commission(s) updated.");
    }
}
