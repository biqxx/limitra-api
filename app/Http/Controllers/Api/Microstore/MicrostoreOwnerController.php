<?php

namespace App\Http\Controllers\Api\Microstore;

use App\Http\Controllers\Api\BaseController;
use App\Models\Microstore\Microstore;
use App\Models\Microstore\MicrostoreEvent;
use App\Models\Microstore\MicrostoreProduct;
use App\Notifications\Microstore\MicrostoreRegisteredNotification;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MicrostoreOwnerController extends BaseController
{
    public function __construct(
        private readonly BusinessSettingsService $settings,
    ) {}

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

        return $this->success([
            'microstore_id' => $microstore->id,
            'name' => $microstore->name,
            'slug' => $microstore->slug,
            'views_count' => (int) $microstore->views_count,
            'clicks_count' => $totalClicks,
            'orders_count' => (int) $microstore->orders_count,
            'total_revenue' => (float) $microstore->total_revenue,
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
