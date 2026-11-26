<?php

namespace App\Http\Controllers\Api\Microstore;

use App\Http\Controllers\Api\BaseController;
use App\Models\Microstore\Microstore;
use App\Models\Microstore\MicrostoreEvent;
use App\Models\Microstore\MicrostoreProduct;
use App\Models\Order\Order;
use App\Models\Order\OrderItem;
use App\Notifications\Microstore\MicrostoreRegisteredNotification;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class MicrostoreOwnerController extends BaseController
{
    public function __construct(
        private readonly BusinessSettingsService $settings,
    ) {}

    #[OA\Get(
        path: '/my-microstore',
        tags: ['My Microstore'],
        summary: 'Get authenticated owner microstore',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Owner microstore details', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    #[OA\Get(
        path: '/my-celebrity-store',
        tags: ['My Celebrity Store'],
        summary: 'Get authenticated owner celebrity store',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Owner celebrity store details', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function show(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $storeType = $this->resolveStoreType($request);
        $microstore = Microstore::query()
            ->with(['curatedProducts.product'])
            ->where('user_id', $user->id)
            ->where('type', $storeType)
            ->first();

        if (! $microstore) {
            return $this->error('Microstore not found for this account.', 404);
        }

        return $this->success($microstore);
    }

    #[OA\Post(
        path: '/my-microstore',
        tags: ['My Microstore'],
        summary: 'Create microstore and assign microstore_owner role',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Microstore created', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    #[OA\Post(
        path: '/my-celebrity-store',
        tags: ['My Celebrity Store'],
        summary: 'Create celebrity store and assign celebrity_store_owner role',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Celebrity store created', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function store(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $storeType = $this->resolveStoreType($request);

        if (Microstore::where('user_id', $user->id)->where('type', $storeType)->exists()) {
            $label = $storeType === 'celebrity' ? 'celebrity store' : 'microstore';

            return $this->error("You already have an active {$label}.", 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:100', 'unique:microstores,slug'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string'],
            'logo_url' => ['nullable', 'url', 'max:500'],
            'banner_url' => ['nullable', 'url', 'max:500'],
            'theme_color' => ['nullable', 'string', 'max:20'],
            'settings' => ['nullable', 'array'],
        ]);

        $slug = ! empty($validated['slug'])
            ? Str::slug($validated['slug'])
            : Str::slug($validated['name']).'-'.strtolower(Str::random(4));

        $requireModeration = (bool) $this->settings->value('microstores.require_moderation', false);

        $microstore = Microstore::create([
            'user_id' => $user->id,
            'type' => $storeType,
            'name' => $validated['name'],
            'slug' => $slug,
            'tagline' => $validated['tagline'] ?? null,
            'bio' => $validated['bio'] ?? null,
            'logo_url' => $validated['logo_url'] ?? null,
            'banner_url' => $validated['banner_url'] ?? null,
            'theme_color' => $validated['theme_color'] ?? '#000000',
            'status' => $requireModeration ? 'pending_review' : 'active',
            'moderation_status' => $requireModeration ? 'pending' : 'approved',
            'settings' => $validated['settings'] ?? [],
        ]);

        $microstore->assignOwnerRole();

        $user->notify(new MicrostoreRegisteredNotification(
            $microstore->name,
            $microstore->slug,
            $requireModeration,
        ));

        return $this->success($microstore, 'Microstore created successfully.', 201);
    }

    #[OA\Patch(
        path: '/my-microstore',
        tags: ['My Microstore'],
        summary: 'Update authenticated owner microstore',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Microstore updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    #[OA\Patch(
        path: '/my-celebrity-store',
        tags: ['My Celebrity Store'],
        summary: 'Update authenticated owner celebrity store',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Celebrity store updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function update(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $microstore = $this->ownedStoreOrFail($request, $user->id);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string'],
            'logo_url' => ['nullable', 'url', 'max:500'],
            'banner_url' => ['nullable', 'url', 'max:500'],
            'theme_color' => ['nullable', 'string', 'max:20'],
            'settings' => ['nullable', 'array'],
        ]);

        $microstore->update($validated);

        return $this->success($microstore->fresh(), 'Microstore updated successfully.');
    }

    #[OA\Post(
        path: '/my-microstore/products',
        tags: ['My Microstore'],
        summary: 'Add product to microstore',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Product added', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    #[OA\Post(
        path: '/my-celebrity-store/products',
        tags: ['My Celebrity Store'],
        summary: 'Add product to celebrity store',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Product added', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function addProduct(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $microstore = $this->ownedStoreOrFail($request, $user->id);

        $validated = $request->validate([
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('status', 'active')->whereNull('deleted_at'),
            ],
            'custom_title' => ['nullable', 'string', 'max:255'],
            'custom_description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        if (MicrostoreProduct::where('microstore_id', $microstore->id)->where('product_id', $validated['product_id'])->exists()) {
            return $this->error('Product is already in your microstore.', 422);
        }

        $item = MicrostoreProduct::create([
            'microstore_id' => $microstore->id,
            'product_id' => $validated['product_id'],
            'custom_title' => $validated['custom_title'] ?? null,
            'custom_description' => $validated['custom_description'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'is_pinned' => (bool) ($validated['is_pinned'] ?? false),
        ]);

        return $this->success($item->load('product'), 'Product added to microstore.', 201);
    }

    #[OA\Patch(
        path: '/my-microstore/products/{product}',
        tags: ['My Microstore'],
        summary: 'Update curated microstore product',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Product updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    #[OA\Patch(
        path: '/my-celebrity-store/products/{product}',
        tags: ['My Celebrity Store'],
        summary: 'Update curated celebrity store product',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Product updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function updateProduct(Request $request, int $productId): JsonResponse
    {
        $user = $request->user('api');
        $microstore = $this->ownedStoreOrFail($request, $user->id);

        $item = MicrostoreProduct::where('microstore_id', $microstore->id)
            ->where('product_id', $productId)
            ->firstOrFail();

        $validated = $request->validate([
            'custom_title' => ['nullable', 'string', 'max:255'],
            'custom_description' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        $item->update($validated);

        return $this->success($item->fresh()->load('product'), 'Microstore product updated.');
    }

    #[OA\Delete(
        path: '/my-microstore/products/{product}',
        tags: ['My Microstore'],
        summary: 'Remove curated product from microstore',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Product removed', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    #[OA\Delete(
        path: '/my-celebrity-store/products/{product}',
        tags: ['My Celebrity Store'],
        summary: 'Remove curated product from celebrity store',
        security: [['bearerAuth' => []]],
        parameters: [new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer'))],
        responses: [new OA\Response(response: 200, description: 'Product removed', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function removeProduct(Request $request, int $productId): JsonResponse
    {
        $user = $request->user('api');
        $microstore = $this->ownedStoreOrFail($request, $user->id);

        $item = MicrostoreProduct::where('microstore_id', $microstore->id)
            ->where('product_id', $productId)
            ->firstOrFail();

        $item->delete();

        return $this->success(null, 'Product removed from microstore.');
    }

    #[OA\Get(
        path: '/my-microstore/analytics',
        tags: ['My Microstore'],
        summary: 'Get microstore analytics, order status breakdown, and top performing products',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Analytics retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    #[OA\Get(
        path: '/my-celebrity-store/analytics',
        tags: ['My Celebrity Store'],
        summary: 'Get celebrity store analytics, order status breakdown, and top performing products',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Analytics retrieved', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function analytics(Request $request): JsonResponse
    {
        $user = $request->user('api');
        $microstore = $this->ownedStoreOrFail($request, $user->id);

        $totalClicks = MicrostoreEvent::where('microstore_id', $microstore->id)
            ->where('event_type', 'product_click')
            ->count();

        $recentEvents = MicrostoreEvent::where('microstore_id', $microstore->id)
            ->latest('id')
            ->limit(20)
            ->get();

        $orderStatusBreakdown = Order::query()
            ->where('microstore_id', $microstore->id)
            ->select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        $topProducts = OrderItem::query()
            ->whereHas('order', fn ($q) => $q->where('microstore_id', $microstore->id)->where('status', '!=', 'cancelled'))
            ->select(
                'product_id',
                DB::raw('MAX(product_name) as product_name'),
                DB::raw('SUM(quantity) as units_sold'),
                DB::raw('COALESCE(SUM(line_total), 0) as revenue')
            )
            ->groupBy('product_id')
            ->orderByDesc('revenue')
            ->limit(10)
            ->get()
            ->map(fn ($row): array => [
                'product_id' => (int) $row->product_id,
                'product_name' => $row->product_name,
                'units_sold' => (int) $row->units_sold,
                'revenue' => (float) $row->revenue,
            ])->values();

        return $this->success([
            'microstore_id' => $microstore->id,
            'name' => $microstore->name,
            'slug' => $microstore->slug,
            'views_count' => (int) $microstore->views_count,
            'clicks_count' => $totalClicks,
            'orders_count' => (int) $microstore->orders_count,
            'total_revenue' => (float) $microstore->total_revenue,
            'order_status_breakdown' => $orderStatusBreakdown,
            'top_performing_products' => $topProducts,
            'recent_events' => $recentEvents,
        ], 'Microstore analytics retrieved.');
    }

    private function ownedStoreOrFail(Request $request, int $userId): Microstore
    {
        return Microstore::where('user_id', $userId)
            ->where('type', $this->resolveStoreType($request))
            ->firstOrFail();
    }

    private function resolveStoreType(Request $request): string
    {
        $type = $request->route('storeType') ?? $request->input('type', 'microstore');

        return in_array($type, ['microstore', 'celebrity'], true) ? $type : 'microstore';
    }
}
