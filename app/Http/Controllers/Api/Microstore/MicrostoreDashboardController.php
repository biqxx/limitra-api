<?php

namespace App\Http\Controllers\Api\Microstore;

use App\Http\Controllers\Api\BaseController;
use App\Models\Microstore\Microstore;
use App\Models\Microstore\MicrostoreProduct;
use App\Models\Microstore\MicrostoreWithdrawal;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Models\Product\Review;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;

class MicrostoreDashboardController extends BaseController
{
    public function __construct(
        private readonly BusinessSettingsService $settings,
    ) {}

    /**
     * Orders listed by products (product-first rows), NOT grouped by customer.
     */
    #[OA\Get(
        path: '/my-microstore/orders',
        tags: ['My Microstore'],
        summary: 'List microstore orders grouped/listed by product items',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Product-first store orders', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function orders(Request $request): JsonResponse
    {
        $microstore = $this->ownedStoreOrFail($request);
        $status = $request->input('status');
        $search = trim((string) $request->input('search', ''));
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $query = OrderItem::query()
            ->with(['order', 'product.productImages'])
            ->whereHas('order', function ($q) use ($microstore, $status): void {
                $q->where('microstore_id', $microstore->id)
                    ->when($status, fn ($oq) => $oq->where('status', $status));
            })
            ->when($search !== '', function ($q) use ($search): void {
                $needle = '%'.mb_strtolower($search).'%';
                $q->where(function ($sq) use ($needle): void {
                    $sq->whereRaw('LOWER(product_name) LIKE ?', [$needle])
                        ->orWhereRaw('LOWER(COALESCE(sku, \'\')) LIKE ?', [$needle])
                        ->orWhereHas('order', fn ($oq) => $oq->whereRaw('LOWER(number) LIKE ?', [$needle]));
                });
            })
            ->latest('id');

        $paginator = $query->paginate($perPage);

        $items = collect($paginator->items())->map(function (OrderItem $item): array {
            $order = $item->order;
            $product = $item->product;
            $image = $product?->productImages?->first()?->path
                ?? (is_array($product?->images) ? ($product->images[0] ?? null) : null);

            return [
                'order_item_id' => $item->id,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'product_image' => $image,
                'sku' => $item->sku,
                'selected_options' => $item->selected_options ?? [],
                'quantity' => (int) $item->quantity,
                'unit_price' => (float) ($item->unit_price ?? $item->price_at_purchase ?? 0),
                'line_total' => (float) ($item->line_total ?? 0),
                'order_id' => $order?->id,
                'order_number' => $order?->number,
                'order_status' => $order?->status,
                'payment_status' => $order?->payment_status,
                'fulfilment_status' => $order?->fulfilment_status,
                'currency' => $order?->currency ?? 'NGN',
                'ordered_at' => $order?->created_at?->toIso8601String(),
                'buyer_ref' => $order?->user_id ? 'CUST-'.$order->user_id : 'GUEST',
            ];
        })->values();

        $statusBreakdown = Order::query()
            ->where('microstore_id', $microstore->id)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        return $this->success([
            'items' => $items,
            'status_breakdown' => $statusBreakdown,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Store product orders retrieved.');
    }

    /**
     * Bulk update status on orders belonging to this store.
     */
    #[OA\Patch(
        path: '/my-microstore/orders/bulk-status',
        tags: ['My Microstore'],
        summary: 'Bulk update status for microstore orders',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Orders updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function bulkOrderStatus(Request $request): JsonResponse
    {
        $microstore = $this->ownedStoreOrFail($request);

        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['integer'],
            'status' => ['required', 'in:pending,confirmed,processing,shipped,delivered,cancelled'],
            'fulfilment_status' => ['nullable', 'in:unfulfilled,processing,shipped,delivered,cancelled'],
        ]);

        $orderIds = array_values(array_unique(array_map('intval', $validated['order_ids'])));
        $newStatus = $validated['status'];
        $fulfilment = $validated['fulfilment_status'] ?? match ($newStatus) {
            'delivered' => 'delivered',
            'shipped' => 'shipped',
            'processing', 'confirmed' => 'processing',
            'cancelled' => 'cancelled',
            default => 'unfulfilled',
        };

        $updatedIds = DB::transaction(function () use ($microstore, $orderIds, $newStatus, $fulfilment): array {
            $orders = Order::query()
                ->where('microstore_id', $microstore->id)
                ->whereIn('id', $orderIds)
                ->lockForUpdate()
                ->get();

            $ids = [];
            foreach ($orders as $order) {
                $order->update([
                    'status' => $newStatus,
                    'fulfilment_status' => $fulfilment,
                ]);
                $ids[] = $order->id;
            }

            return $ids;
        });

        return $this->success([
            'updated_count' => count($updatedIds),
            'updated_order_ids' => $updatedIds,
            'status' => $newStatus,
            'fulfilment_status' => $fulfilment,
        ], 'Orders updated.');
    }

    #[OA\Get(
        path: '/my-microstore/inventory',
        tags: ['My Microstore'],
        summary: 'Get microstore curated product inventory',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Inventory retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function inventory(Request $request): JsonResponse
    {
        $microstore = $this->ownedStoreOrFail($request);

        $curated = MicrostoreProduct::query()
            ->with(['product.productImages', 'product.variants'])
            ->where('microstore_id', $microstore->id)
            ->orderByDesc('is_pinned')
            ->orderBy('sort_order')
            ->get();

        $summary = [
            'total_items' => $curated->count(),
            'in_stock' => 0,
            'low_stock' => 0,
            'out_of_stock' => 0,
        ];

        $items = $curated->map(function (MicrostoreProduct $entry) use (&$summary): array {
            $product = $entry->product;
            $stock = (int) ($product?->stock ?? 0);
            $threshold = (int) ($product?->low_stock_threshold ?? 5);
            $outOfStock = $stock <= 0;
            $lowStock = ! $outOfStock && $stock <= $threshold;

            if ($outOfStock) {
                $summary['out_of_stock']++;
            } elseif ($lowStock) {
                $summary['low_stock']++;
            } else {
                $summary['in_stock']++;
            }

            $image = $product?->productImages?->first()?->path
                ?? (is_array($product?->images) ? ($product->images[0] ?? null) : null);

            return [
                'product_id' => $entry->product_id,
                'name' => $entry->custom_title ?: $product?->name,
                'sku' => $product?->sku,
                'image' => $image,
                'price' => (float) ($product?->effectivePrice() ?? $product?->price ?? 0),
                'stock' => $stock,
                'low_stock_threshold' => $threshold,
                'in_stock' => ! $outOfStock,
                'is_low_stock' => $lowStock,
                'status' => $product?->status ?? 'draft',
                'variants_count' => $product?->variants?->count() ?? 0,
                'variants' => ($product?->variants ?? collect())->map(fn ($v) => [
                    'id' => $v->id,
                    'sku' => $v->sku,
                    'attributes' => $v->attributes,
                    'stock' => (int) $v->stock,
                    'price' => (float) ($v->price ?? $product?->price ?? 0),
                    'status' => $v->status,
                ])->values(),
            ];
        })->values();

        return $this->success([
            'summary' => $summary,
            'items' => $items,
        ], 'Store inventory retrieved.');
    }

    #[OA\Get(
        path: '/my-microstore/customers',
        tags: ['My Microstore'],
        summary: 'List microstore customers',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Customers retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function customers(Request $request): JsonResponse
    {
        $microstore = $this->ownedStoreOrFail($request);
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $rows = Order::query()
            ->with('user:id,name,username,email')
            ->where('microstore_id', $microstore->id)
            ->whereNotNull('user_id')
            ->select(
                'user_id',
                DB::raw('COUNT(*) as orders_count'),
                DB::raw('COALESCE(SUM(COALESCE(total_amount, grand_total)), 0) as total_spent'),
                DB::raw('MAX(created_at) as last_order_at')
            )
            ->groupBy('user_id')
            ->orderByDesc('total_spent')
            ->paginate($perPage);

        $items = collect($rows->items())->map(fn ($r): array => [
            'customer_id' => $r->user_id,
            'name' => $r->user?->name ?: $r->user?->username ?: 'Customer #'.$r->user_id,
            'email' => $r->user?->email,
            'orders_count' => (int) $r->orders_count,
            'total_spent' => (float) $r->total_spent,
            'last_order_at' => $r->last_order_at,
        ])->values();

        return $this->success([
            'items' => $items,
            'pagination' => [
                'current_page' => $rows->currentPage(),
                'per_page' => $rows->perPage(),
                'total' => $rows->total(),
                'last_page' => $rows->lastPage(),
            ],
        ], 'Store customers retrieved.');
    }

    #[OA\Get(
        path: '/my-microstore/reviews',
        tags: ['My Microstore'],
        summary: 'List reviews for microstore products',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Reviews retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function reviews(Request $request): JsonResponse
    {
        $microstore = $this->ownedStoreOrFail($request);
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $productIds = MicrostoreProduct::where('microstore_id', $microstore->id)->pluck('product_id');

        $paginator = Review::query()
            ->with(['user:id,name,username', 'product:id,name,slug'])
            ->whereIn('product_id', $productIds)
            ->latest('id')
            ->paginate($perPage);

        $avgRating = (float) (Review::whereIn('product_id', $productIds)->avg('rating') ?? 0);

        $items = collect($paginator->items())->map(fn (Review $rev): array => [
            'id' => $rev->id,
            'product_id' => $rev->product_id,
            'product_name' => $rev->product?->name,
            'rating' => (int) $rev->rating,
            'title' => $rev->title,
            'comment' => $rev->comment,
            'reviewer_name' => $rev->user?->name ?: $rev->user?->username ?: 'Verified Buyer',
            'verified_purchase' => (bool) $rev->verified_purchase,
            'created_at' => $rev->created_at?->toIso8601String(),
        ])->values();

        return $this->success([
            'average_rating' => round($avgRating, 2),
            'total_reviews' => $paginator->total(),
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Store reviews retrieved.');
    }

    #[OA\Get(
        path: '/my-microstore/earnings',
        tags: ['My Microstore'],
        summary: 'Get microstore commission earnings summary',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Earnings retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function earnings(Request $request): JsonResponse
    {
        $microstore = $this->ownedStoreOrFail($request);
        $summary = $this->computeEarningsSummary($microstore);

        $recentOrders = Order::query()
            ->where('microstore_id', $microstore->id)
            ->where('status', '!=', 'cancelled')
            ->latest('id')
            ->limit(25)
            ->get()
            ->map(function (Order $order) use ($summary): array {
                $total = (float) ($order->total_amount ?? $order->grand_total ?? 0);
                $commission = round($total * ($summary['commission_rate'] / 100), 2);

                return [
                    'order_id' => $order->id,
                    'order_number' => $order->number,
                    'order_total' => $total,
                    'commission_amount' => $commission,
                    'status' => $order->status,
                    'payment_status' => $order->payment_status,
                    'earning_status' => $order->status === 'delivered' ? 'available' : 'pending',
                    'created_at' => $order->created_at?->toIso8601String(),
                ];
            })->values();

        return $this->success([
            ...$summary,
            'entries' => $recentOrders,
        ], 'Store earnings retrieved.');
    }

    #[OA\Get(
        path: '/my-microstore/withdrawals',
        tags: ['My Microstore'],
        summary: 'List microstore withdrawal requests',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Withdrawals retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function withdrawals(Request $request): JsonResponse
    {
        $microstore = $this->ownedStoreOrFail($request);
        $summary = $this->computeEarningsSummary($microstore);
        $perPage = min(max($request->integer('per_page', 20), 1), 100);

        $paginator = MicrostoreWithdrawal::query()
            ->where('microstore_id', $microstore->id)
            ->latest('id')
            ->paginate($perPage);

        return $this->success([
            'available_balance' => $summary['available_balance'],
            'pending_withdrawals' => $summary['pending_withdrawals'],
            'withdrawn_total' => $summary['withdrawn_total'],
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ], 'Store withdrawals retrieved.');
    }

    #[OA\Post(
        path: '/my-microstore/withdrawals',
        tags: ['My Microstore'],
        summary: 'Request a microstore payout withdrawal',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Withdrawal submitted', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function requestWithdrawal(Request $request): JsonResponse
    {
        $microstore = $this->ownedStoreOrFail($request);
        $user = $request->user('api');

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:100'],
            'payout_method' => ['nullable', 'string', 'max:50'],
            'payout_details' => ['required', 'array'],
            'payout_details.bank_name' => ['required', 'string', 'max:120'],
            'payout_details.account_number' => ['required', 'string', 'max:40'],
            'payout_details.account_name' => ['required', 'string', 'max:150'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $amount = round((float) $validated['amount'], 2);

        $withdrawal = DB::transaction(function () use ($microstore, $user, $validated, $amount): MicrostoreWithdrawal {
            Microstore::whereKey($microstore->id)->lockForUpdate()->first();
            $summary = $this->computeEarningsSummary($microstore);

            if ($amount > $summary['available_balance']) {
                abort(422, 'Requested amount exceeds available store balance.');
            }

            return MicrostoreWithdrawal::create([
                'microstore_id' => $microstore->id,
                'user_id' => $user->id,
                'amount' => $amount,
                'currency' => 'NGN',
                'status' => 'pending',
                'payout_method' => $validated['payout_method'] ?? 'bank_transfer',
                'payout_details' => $validated['payout_details'],
                'reference' => 'MSW-'.strtoupper(Str::random(10)),
                'notes' => $validated['notes'] ?? null,
            ]);
        });

        return $this->success($withdrawal, 'Withdrawal request submitted.', 201);
    }

    #[OA\Get(
        path: '/my-microstore/transactions',
        tags: ['My Microstore'],
        summary: 'List microstore financial transactions',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Transactions retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function transactions(Request $request): JsonResponse
    {
        $microstore = $this->ownedStoreOrFail($request);
        $summary = $this->computeEarningsSummary($microstore);

        $credits = Order::query()
            ->where('microstore_id', $microstore->id)
            ->where('status', '!=', 'cancelled')
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(function (Order $order) use ($summary): array {
                $total = (float) ($order->total_amount ?? $order->grand_total ?? 0);

                return [
                    'id' => 'ord-'.$order->id,
                    'type' => 'commission',
                    'direction' => 'credit',
                    'reference' => $order->number,
                    'amount' => round($total * ($summary['commission_rate'] / 100), 2),
                    'status' => $order->status === 'delivered' ? 'settled' : 'pending',
                    'description' => "Commission from order {$order->number}",
                    'created_at' => $order->created_at?->toIso8601String(),
                ];
            });

        $debits = MicrostoreWithdrawal::query()
            ->where('microstore_id', $microstore->id)
            ->latest('id')
            ->limit(50)
            ->get()
            ->map(fn (MicrostoreWithdrawal $w): array => [
                'id' => 'wd-'.$w->id,
                'type' => 'withdrawal',
                'direction' => 'debit',
                'reference' => $w->reference,
                'amount' => (float) $w->amount,
                'status' => $w->status,
                'description' => 'Store payout request',
                'created_at' => $w->created_at?->toIso8601String(),
            ]);

        $merged = $credits->concat($debits)
            ->sortByDesc('created_at')
            ->values();

        return $this->success([
            'items' => $merged,
            'total' => $merged->count(),
        ], 'Store transactions retrieved.');
    }

    /**
     * @return array<string, float>
     */
    private function computeEarningsSummary(Microstore $microstore): array
    {
        $defaultRate = $microstore->isCelebrityStore() ? 15.0 : 10.0;
        $commissionRate = (float) $this->settings->value('microstores.commission_rate', $defaultRate);

        $grossSales = (float) Order::query()
            ->where('microstore_id', $microstore->id)
            ->where('status', '!=', 'cancelled')
            ->sum(DB::raw('COALESCE(total_amount, grand_total, 0)'));

        $deliveredSales = (float) Order::query()
            ->where('microstore_id', $microstore->id)
            ->where('status', 'delivered')
            ->sum(DB::raw('COALESCE(total_amount, grand_total, 0)'));

        $pendingSales = max(0.0, $grossSales - $deliveredSales);

        $totalEarned = round($grossSales * ($commissionRate / 100), 2);
        $settledEarned = round($deliveredSales * ($commissionRate / 100), 2);
        $pendingEarnings = round($pendingSales * ($commissionRate / 100), 2);

        $withdrawnTotal = (float) MicrostoreWithdrawal::query()
            ->where('microstore_id', $microstore->id)
            ->whereIn('status', ['approved', 'paid'])
            ->sum('amount');

        $pendingWithdrawals = (float) MicrostoreWithdrawal::query()
            ->where('microstore_id', $microstore->id)
            ->where('status', 'pending')
            ->sum('amount');

        $availableBalance = max(0.0, round($settledEarned - $withdrawnTotal - $pendingWithdrawals, 2));

        return [
            'commission_rate' => $commissionRate,
            'gross_sales' => round($grossSales, 2),
            'total_earned' => $totalEarned,
            'settled_earnings' => $settledEarned,
            'pending_earnings' => $pendingEarnings,
            'withdrawn_total' => round($withdrawnTotal, 2),
            'pending_withdrawals' => round($pendingWithdrawals, 2),
            'available_balance' => $availableBalance,
        ];
    }

    private function ownedStoreOrFail(Request $request): Microstore
    {
        $user = $request->user('api');
        $type = $request->route('storeType') ?? $request->input('type', 'microstore');
        $storeType = in_array($type, ['microstore', 'celebrity'], true) ? $type : 'microstore';

        return Microstore::where('user_id', $user->id)
            ->where('type', $storeType)
            ->firstOrFail();
    }
}

