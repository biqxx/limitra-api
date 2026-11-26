<?php

namespace App\Http\Controllers\Api\Microstore;

use App\Http\Controllers\Api\BaseController;
use App\Models\Microstore\Microstore;
use App\Models\Microstore\MicrostoreEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class MicrostorePublicController extends BaseController
{
    #[OA\Get(
        path: '/microstores',
        tags: ['Microstores'],
        summary: 'List active microstores',
        parameters: [
            new OA\Parameter(name: 'featured', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 16)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated microstores', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function index(Request $request): JsonResponse
    {
        $storeType = $this->resolveStoreType($request);

        $query = Microstore::query()
            ->with('user:id,username,email')
            ->when($storeType, fn ($q) => $q->where('type', $storeType))
            ->where('status', 'active')
            ->where('moderation_status', 'approved')
            ->latest('id');

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where(function ($q) use ($search): void {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('tagline', 'like', "%{$search}%")
                    ->orWhere('bio', 'like', "%{$search}%");
            });
        }

        $perPage = min(max($request->integer('per_page', 16), 1), 50);
        $paginator = $query->paginate($perPage);

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

    #[OA\Get(
        path: '/microstores/{slug}',
        tags: ['Microstores'],
        summary: 'Get public microstore by slug',
        parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 200, description: 'Microstore details', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function show(string $slug, Request $request): JsonResponse
    {
        $storeType = $this->resolveStoreType($request);

        $microstore = Microstore::query()
            ->with([
                'user:id,username,email',
                'curatedProducts' => fn ($query) => $query
                    ->whereHas('product', fn ($productQuery) => $productQuery->where('status', 'active'))
                    ->with('product'),
            ])
            ->when($storeType, fn ($q) => $q->where('type', $storeType))
            ->where('slug', $slug)
            ->where('status', 'active')
            ->where('moderation_status', 'approved')
            ->firstOrFail();

        $microstore->increment('views_count');

        MicrostoreEvent::create([
            'microstore_id' => $microstore->id,
            'user_id' => $request->user('api')?->id,
            'event_type' => 'view',
            'session_id' => $request->header('X-Session-ID'),
            'created_at' => now(),
        ]);

        return $this->success($microstore);
    }

    #[OA\Get(
        path: '/microstores/{slug}/products',
        tags: ['Microstores'],
        summary: 'List curated products in a microstore',
        parameters: [
            new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [new OA\Response(response: 200, description: 'Paginated microstore products', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function products(string $slug, Request $request): JsonResponse
    {
        $storeType = $this->resolveStoreType($request);

        $microstore = Microstore::query()
            ->when($storeType, fn ($q) => $q->where('type', $storeType))
            ->where('slug', $slug)
            ->where('status', 'active')
            ->where('moderation_status', 'approved')
            ->firstOrFail();

        $paginator = $microstore->curatedProducts()
            ->whereHas('product', fn ($query) => $query->where('status', 'active'))
            ->with('product')
            ->paginate($request->integer('per_page', 20));

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

    #[OA\Post(
        path: '/microstores/{slug}/clicks',
        tags: ['Microstores'],
        summary: 'Track a microstore or product click',
        parameters: [new OA\Parameter(name: 'slug', in: 'path', required: true, schema: new OA\Schema(type: 'string'))],
        responses: [new OA\Response(response: 201, description: 'Click tracked', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function trackClick(string $slug, Request $request): JsonResponse
    {
        $storeType = $this->resolveStoreType($request);

        $microstore = Microstore::query()
            ->when($storeType, fn ($q) => $q->where('type', $storeType))
            ->where('slug', $slug)
            ->where('status', 'active')
            ->where('moderation_status', 'approved')
            ->firstOrFail();

        $validated = $request->validate([
            'product_id' => [
                'required',
                'integer',
                Rule::exists('products', 'id')->where('status', 'active')->whereNull('deleted_at'),
            ],
            'session_id' => ['nullable', 'string', 'max:100'],
        ]);

        MicrostoreEvent::create([
            'microstore_id' => $microstore->id,
            'user_id' => $request->user('api')?->id,
            'event_type' => 'product_click',
            'product_id' => $validated['product_id'],
            'session_id' => $validated['session_id'] ?? $request->header('X-Session-ID'),
            'created_at' => now(),
        ]);

        return $this->success(null, 'Click tracked.');
    }

    private function resolveStoreType(Request $request): ?string
    {
        $type = $request->route('storeType') ?? $request->input('type');

        return in_array($type, ['microstore', 'celebrity'], true) ? $type : null;
    }
}
