<?php

namespace App\Http\Controllers\Api\Affiliate;

use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\AffiliateCommissionResource;
use App\Http\Resources\AffiliateReferralResource;
use App\Http\Resources\AffiliateSaleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Self-service portal for affiliates.
 * All routes require auth:api + role:affiliate.
 */
class DashboardController extends BaseController
{
    #[OA\Get(
        path: '/affiliate/dashboard',
        tags: ['Affiliate Portal'],
        summary: 'Affiliate dashboard stats',
        security: [['bearerAuth' => []]],
        description: 'Returns summary stats: earnings, referrals, sales, pending commissions. Requires affiliate role.',
        responses: [
            new OA\Response(response: 200, description: 'Affiliate dashboard', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 404, description: 'No affiliate account found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        $affiliate = auth('api')->user()->affiliate;

        if (! $affiliate) {
            return $this->error('No affiliate account found for this user.', 404);
        }

        $summary = [
            'id' => $affiliate->id,
            'code' => $affiliate->code,
            'slug' => $affiliate->slug,
            'status' => $affiliate->status,
            'commission_rate' => $affiliate->commission_rate,
            'total_earnings' => $affiliate->total_earnings,
            'total_paid' => $affiliate->total_paid,
            'pending_balance' => $affiliate->pending_balance,

            'total_referrals' => $affiliate->referrals()->count(),
            'total_sales' => $affiliate->sales()->count(),
            'direct_sales' => $affiliate->sales()->where('sale_type', 'direct')->count(),
            'indirect_sales' => $affiliate->sales()->where('sale_type', 'indirect')->count(),

            'pending_commissions' => $affiliate->commissions()->where('payment_status', 'pending')->count(),
            'approved_commissions' => $affiliate->commissions()->where('payment_status', 'approved')->count(),
        ];

        return $this->success($summary);
    }

    #[OA\Get(
        path: '/affiliate/referrals',
        tags: ['Affiliate Portal'],
        summary: 'List referrals',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated referral list', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 404, description: 'No affiliate account found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function referrals(Request $request): JsonResponse
    {
        $affiliate = auth('api')->user()->affiliate;

        if (! $affiliate) {
            return $this->error('No affiliate account found.', 404);
        }

        $referrals = $affiliate->referrals()
            ->with('user.profile')
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => AffiliateReferralResource::collection($referrals->getCollection()),
            'pagination' => [
                'current_page' => $referrals->currentPage(),
                'last_page' => $referrals->lastPage(),
                'per_page' => $referrals->perPage(),
                'total' => $referrals->total(),
                'from' => $referrals->firstItem(),
                'to' => $referrals->lastItem(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/affiliate/sales',
        tags: ['Affiliate Portal'],
        summary: 'List affiliate sales',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'sale_type', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['direct', 'indirect'])),
            new OA\Parameter(name: 'from_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated sales list', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 404, description: 'No affiliate account found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function sales(Request $request): JsonResponse
    {
        $request->validate([
            'sale_type' => ['sometimes', 'in:direct,indirect'],
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date', 'after_or_equal:from_date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $affiliate = auth('api')->user()->affiliate;

        if (! $affiliate) {
            return $this->error('No affiliate account found.', 404);
        }

        $sales = $affiliate->sales()
            ->with(['product', 'commission'])
            ->when($request->sale_type, fn ($q) => $q->where('sale_type', $request->sale_type))
            ->when($request->from_date, fn ($q) => $q->whereDate('created_at', '>=', $request->from_date))
            ->when($request->to_date, fn ($q) => $q->whereDate('created_at', '<=', $request->to_date))
            ->orderByDesc('id')
            ->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => AffiliateSaleResource::collection($sales->getCollection()),
            'pagination' => [
                'current_page' => $sales->currentPage(),
                'last_page' => $sales->lastPage(),
                'per_page' => $sales->perPage(),
                'total' => $sales->total(),
                'from' => $sales->firstItem(),
                'to' => $sales->lastItem(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/affiliate/commissions',
        tags: ['Affiliate Portal'],
        summary: 'List own commissions',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'payment_status', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['pending', 'approved', 'paid', 'rejected'])),
            new OA\Parameter(name: 'from_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated commission list', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 404, description: 'No affiliate account found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function commissions(Request $request): JsonResponse
    {
        $request->validate([
            'payment_status' => ['sometimes', 'in:pending,approved,paid,rejected'],
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date', 'after_or_equal:from_date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $affiliate = auth('api')->user()->affiliate;

        if (! $affiliate) {
            return $this->error('No affiliate account found.', 404);
        }

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
