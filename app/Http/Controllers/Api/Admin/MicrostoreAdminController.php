<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\Microstore\Microstore;
use App\Models\Microstore\MicrostoreProduct;
use App\Notifications\Microstore\MicrostoreModeratedNotification;
use App\Services\Admin\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MicrostoreAdminController extends BaseController
{
    public function __construct(private readonly AuditEventService $auditEvents) {}

    public function index(Request $request): JsonResponse
    {
        $query = Microstore::query()->with('user:id,username,email')->latest('id');

        $storeType = $request->route('storeType') ?? $request->input('type');
        if (in_array($storeType, ['microstore', 'celebrity'], true)) {
            $query->where('type', $storeType);
        }

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('moderation_status')) {
            $query->where('moderation_status', (string) $request->input('moderation_status'));
        }

        if ($request->has('featured')) {
            $query->where('is_featured', $request->boolean('featured'));
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $paginator = $query->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $storeType = $request->route('storeType') ?? $request->input('type', 'microstore');

        $validated = $request->validate([
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                Rule::unique('microstores', 'user_id')->where('type', $storeType),
            ],
            'type' => ['sometimes', 'string', 'in:microstore,celebrity'],
            'name' => ['required', 'string', 'max:150'],
            'slug' => ['nullable', 'string', 'max:100', 'unique:microstores,slug'],
            'tagline' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string'],
            'logo_url' => ['nullable', 'url', 'max:500'],
            'banner_url' => ['nullable', 'url', 'max:500'],
            'theme_color' => ['nullable', 'string', 'max:20'],
            'is_featured' => ['nullable', 'boolean'],
            'settings' => ['nullable', 'array'],
            'product_ids' => ['nullable', 'array'],
            'product_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('products', 'id')->where('status', 'active')->whereNull('deleted_at'),
            ],
        ]);

        $admin = $request->user('api');
        $slug = ! empty($validated['slug'])
            ? Str::slug($validated['slug'])
            : Str::slug($validated['name']).'-'.strtolower(Str::random(4));

        $microstore = DB::transaction(function () use ($validated, $slug, $admin, $storeType): Microstore {
            $store = Microstore::create([
                'user_id' => $validated['user_id'],
                'type' => $storeType,
                'name' => $validated['name'],
                'slug' => $slug,
                'tagline' => $validated['tagline'] ?? null,
                'bio' => $validated['bio'] ?? null,
                'logo_url' => $validated['logo_url'] ?? null,
                'banner_url' => $validated['banner_url'] ?? null,
                'theme_color' => $validated['theme_color'] ?? '#000000',
                'status' => 'active',
                'moderation_status' => 'approved',
                'moderated_by' => $admin?->id,
                'moderated_at' => now(),
                'is_featured' => (bool) ($validated['is_featured'] ?? false),
                'settings' => $validated['settings'] ?? [],
            ]);

            $store->assignOwnerRole();

            foreach ($validated['product_ids'] ?? [] as $index => $productId) {
                MicrostoreProduct::create([
                    'microstore_id' => $store->id,
                    'product_id' => $productId,
                    'sort_order' => $index,
                    'is_pinned' => false,
                ]);
            }

            $this->auditEvents->record(
                action: 'microstore.created',
                actor: $admin,
                subject: $store,
                after: [
                    'name' => $store->name,
                    'slug' => $store->slug,
                    'user_id' => $store->user_id,
                    'is_featured' => $store->is_featured,
                ],
            );

            return $store;
        });

        return $this->success(
            $microstore->load(['user:id,username,email', 'curatedProducts.product']),
            'Celebrity microstore created successfully.',
            201,
        );
    }

    public function show(Request $request, Microstore $microstore): JsonResponse
    {
        $this->assertRouteStoreType($request, $microstore);

        return $this->success($microstore->load([
            'user:id,username,email',
            'moderatedBy:id,username,email',
            'curatedProducts.product',
        ]));
    }

    public function update(Request $request, Microstore $microstore): JsonResponse
    {
        $this->assertRouteStoreType($request, $microstore);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:150'],
            'slug' => ['sometimes', 'string', 'max:100', Rule::unique('microstores', 'slug')->ignore($microstore->id)],
            'tagline' => ['nullable', 'string', 'max:255'],
            'bio' => ['nullable', 'string'],
            'logo_url' => ['nullable', 'url', 'max:500'],
            'banner_url' => ['nullable', 'url', 'max:500'],
            'theme_color' => ['nullable', 'string', 'max:20'],
            'is_featured' => ['sometimes', 'boolean'],
            'settings' => ['nullable', 'array'],
        ]);

        if (isset($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['slug']);
        }

        $microstore->update($validated);

        return $this->success(
            $microstore->fresh()->load(['user:id,username,email', 'curatedProducts.product']),
            'Microstore updated successfully.',
        );
    }

    public function moderate(Request $request, Microstore $microstore): JsonResponse
    {
        $this->assertRouteStoreType($request, $microstore);

        $validated = $request->validate([
            'moderation_status' => ['required', 'string', 'in:approved,rejected,suspended'],
            'moderation_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $admin = $request->user('api');
        $status = match ($validated['moderation_status']) {
            'approved' => 'active',
            'rejected', 'suspended' => 'suspended',
            default => 'pending_review',
        };

        $before = [
            'status' => $microstore->status,
            'moderation_status' => $microstore->moderation_status,
        ];

        $microstore->update([
            'status' => $status,
            'moderation_status' => $validated['moderation_status'],
            'moderation_notes' => $validated['moderation_notes'] ?? null,
            'moderated_by' => $admin?->id,
            'moderated_at' => now(),
        ]);

        $this->auditEvents->record(
            action: 'microstore.moderated',
            actor: $admin,
            subject: $microstore,
            reason: $validated['moderation_status'],
            before: $before,
            after: [
                'status' => $status,
                'moderation_status' => $validated['moderation_status'],
            ],
            metadata: [
                'microstore_id' => $microstore->id,
                'notes' => $validated['moderation_notes'] ?? null,
            ],
        );

        $microstore->user?->notify(new MicrostoreModeratedNotification(
            $microstore->name,
            $microstore->slug,
            $validated['moderation_status'],
            $validated['moderation_notes'] ?? null,
        ));

        return $this->success($microstore->fresh(), 'Microstore moderation status updated.');
    }

    public function toggleFeatured(Request $request, Microstore $microstore): JsonResponse
    {
        $this->assertRouteStoreType($request, $microstore);

        $isFeatured = $request->has('is_featured')
            ? $request->boolean('is_featured')
            : ! $microstore->is_featured;

        $microstore->update(['is_featured' => $isFeatured]);

        return $this->success($microstore->fresh(), 'Microstore featured status updated.');
    }

    private function assertRouteStoreType(Request $request, Microstore $microstore): void
    {
        $routeType = $request->route('storeType');
        if (in_array($routeType, ['microstore', 'celebrity'], true) && $microstore->type !== $routeType) {
            abort(404);
        }
    }
}
