<?php

namespace App\Http\Controllers\Api\Microstore;

use App\Http\Controllers\Api\BaseController;
use App\Models\Microstore\Microstore;
use App\Models\Microstore\MicrostoreEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MicrostorePublicController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Microstore::query()
            ->with('user:id,username,email')
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

    public function show(string $slug, Request $request): JsonResponse
    {
        $microstore = Microstore::query()
            ->with(['user:id,username,email', 'curatedProducts.product'])
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

    public function products(string $slug, Request $request): JsonResponse
    {
        $microstore = Microstore::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->where('moderation_status', 'approved')
            ->firstOrFail();

        $paginator = $microstore->curatedProducts()
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

    public function trackClick(string $slug, Request $request): JsonResponse
    {
        $microstore = Microstore::query()
            ->where('slug', $slug)
            ->where('status', 'active')
            ->firstOrFail();

        $validated = $request->validate([
            'product_id' => ['required', 'integer', 'exists:products,id'],
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
}
