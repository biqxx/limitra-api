<?php

namespace App\Http\Controllers\Api\Affiliate;

use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\AffiliateCommissionResource;
use App\Http\Resources\AffiliateReferralResource;
use App\Http\Resources\AffiliateSaleResource;
use App\Models\Affiliate\Affiliate;
use App\Services\Affiliate\AffiliateTierService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

/**
 * Self-service portal for affiliates.
 * All routes require auth:api + role:affiliate.
 */
class DashboardController extends BaseController
{
    public function __construct(
        private readonly AffiliateTierService $tierService
    ) {}

    private function getAffiliate(): Affiliate
    {
        $user = auth('api')->user();

        return $user->affiliate ?? Affiliate::where('user_id', $user->id)->firstOrFail();
    }

    #[OA\Get(
        path: '/affiliate/dashboard',
        tags: ['Affiliate Portal'],
        summary: 'Affiliate dashboard stats',
        security: [['bearerAuth' => []]],
        description: 'Returns summary stats: earnings, referrals, sales, pending commissions, tier progress, and date-filtered metrics.',
        responses: [
            new OA\Response(response: 200, description: 'Affiliate dashboard', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 404, description: 'No affiliate account found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate();

        $request->validate([
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date', 'after_or_equal:from_date'],
        ]);

        $fromDate = $request->input('from_date');
        $toDate = $request->input('to_date');

        // Tier progression status
        $tierProgress = $this->tierService->evaluateTier($affiliate);

        // Date-filtered queries
        $salesQuery = $affiliate->sales()
            ->when($fromDate, fn ($q) => $q->whereDate('created_at', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('created_at', '<=', $toDate));

        $commissionsQuery = $affiliate->commissions()
            ->when($fromDate, fn ($q) => $q->whereDate('created_at', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('created_at', '<=', $toDate));

        $clicksQuery = $affiliate->clicks()
            ->when($fromDate, fn ($q) => $q->whereDate('created_at', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('created_at', '<=', $toDate));

        $referralsQuery = $affiliate->referrals()
            ->when($fromDate, fn ($q) => $q->whereDate('created_at', '>=', $fromDate))
            ->when($toDate, fn ($q) => $q->whereDate('created_at', '<=', $toDate));

        $totalClicks = (int) $clicksQuery->count();
        $totalConversions = (int) $salesQuery->count();
        $conversionRate = $totalClicks > 0 ? round(($totalConversions / $totalClicks) * 100, 2) : 0.0;

        $summary = [
            'id' => $affiliate->id,
            'code' => $affiliate->code,
            'slug' => $affiliate->slug,
            'status' => $affiliate->status,
            'current_tier' => $affiliate->current_tier ?: 'Bronze',
            'commission_rate' => (float) $affiliate->commission_rate,
            'total_earnings' => (string) $affiliate->total_earnings,
            'total_paid' => (string) $affiliate->total_paid,
            'pending_balance' => (string) $affiliate->pending_balance,
            'held_balance' => number_format($affiliate->getAuthoritativeHeldBalance(), 2, '.', ''),

            'tier_progress' => $tierProgress,

            'metrics' => [
                'from_date' => $fromDate,
                'to_date' => $toDate,
                'total_clicks' => $totalClicks,
                'total_referrals' => $referralsQuery->count(),
                'total_sales' => $totalConversions,
                'direct_sales' => (clone $salesQuery)->where('sale_type', 'direct')->count(),
                'indirect_sales' => (clone $salesQuery)->where('sale_type', 'indirect')->count(),
                'conversion_rate_percentage' => $conversionRate,
                'pending_commissions' => (clone $commissionsQuery)->where('payment_status', 'pending')->count(),
                'approved_commissions' => (clone $commissionsQuery)->where('payment_status', 'approved')->count(),
                'period_earnings' => number_format((float) (clone $commissionsQuery)->whereIn('payment_status', ['approved', 'paid'])->sum('commission_amount'), 2, '.', ''),
            ],

            // Backwards compatibility keys
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
        path: '/affiliate/analytics',
        tags: ['Affiliate Portal'],
        summary: 'Time-series analytics for affiliate',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Affiliate analytics series', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 404, description: 'No affiliate account found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function analytics(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate();

        $request->validate([
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date', 'after_or_equal:from_date'],
        ]);

        $from = $request->filled('from_date')
            ? Carbon::parse($request->input('from_date'))->startOfDay()
            : now()->subDays(30)->startOfDay();

        $to = $request->filled('to_date')
            ? Carbon::parse($request->input('to_date'))->endOfDay()
            : now()->endOfDay();

        $clicksByDay = $affiliate->clicks()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as date, count(*) as count')
            ->groupBy('date')
            ->pluck('count', 'date');

        $salesByDay = $affiliate->sales()
            ->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as date, count(*) as count, sum(sale_amount) as volume')
            ->groupBy('date')
            ->get()
            ->keyBy('date');

        $earningsByDay = $affiliate->commissions()
            ->whereBetween('created_at', [$from, $to])
            ->whereIn('payment_status', ['approved', 'paid'])
            ->selectRaw('DATE(created_at) as date, sum(commission_amount) as amount')
            ->groupBy('date')
            ->pluck('amount', 'date');

        $series = [];
        $period = CarbonPeriod::create($from, '1 day', $to);

        foreach ($period as $date) {
            $formattedDate = $date->toDateString();
            $saleData = $salesByDay->get($formattedDate);

            $series[] = [
                'date' => $formattedDate,
                'clicks' => (int) ($clicksByDay->get($formattedDate) ?? 0),
                'sales' => (int) ($saleData->count ?? 0),
                'sales_volume' => round((float) ($saleData->volume ?? 0.0), 2),
                'earnings' => round((float) ($earningsByDay->get($formattedDate) ?? 0.0), 2),
            ];
        }

        return $this->success([
            'from_date' => $from->toDateString(),
            'to_date' => $to->toDateString(),
            'series' => $series,
        ], 'Affiliate analytics series retrieved.');
    }

    #[OA\Get(
        path: '/affiliate/tier-history',
        tags: ['Affiliate Portal'],
        summary: 'Affiliate tier progression history',
        security: [['bearerAuth' => []]],
        responses: [
            new OA\Response(response: 200, description: 'Affiliate tier history list', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 404, description: 'No affiliate account found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function tierHistory(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate();

        $paginator = $affiliate->tierHistories()
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ], 'Tier progression history retrieved.');
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
        $affiliate = $this->getAffiliate();

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

        $affiliate = $this->getAffiliate();

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

        $affiliate = $this->getAffiliate();

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
