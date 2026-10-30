<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\BaseController;
use App\Models\Content\Video;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class VideoController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = Video::query()->where('status', 'published')->latest('id');

        if ($request->filled('category')) {
            $query->where('category', (string) $request->input('category'));
        }

        if ($request->boolean('featured')) {
            $query->where('is_featured', true);
        }

        $paginator = $query->paginate($request->integer('per_page', 12));

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

    public function show(Video $video): JsonResponse
    {
        return $this->success($video);
    }

    public function event(Request $request, Video $video): JsonResponse
    {
        $validated = $request->validate([
            'event_type' => ['required', 'string', 'in:impression,play,complete,like,share'],
        ]);

        match ($validated['event_type']) {
            'play' => $video->increment('views_count'),
            'like' => $video->increment('likes_count'),
            'share' => $video->increment('shares_count'),
            default => null,
        };

        return $this->success([
            'video_id' => $video->id,
            'event_type' => $validated['event_type'],
        ], 'Video event recorded.');
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $paginator = Video::query()->latest('id')->paginate($request->integer('per_page', 20));

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
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:500'],
            'thumbnail_url' => ['nullable', 'url', 'max:500'],
            'category' => ['nullable', 'string', 'max:100'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'is_featured' => ['nullable', 'boolean'],
            'tagged_product_ids' => ['nullable', 'array'],
            'tagged_product_ids.*' => ['integer', 'exists:products,id'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $slug = Str::slug($validated['title']).'-'.strtolower(Str::random(5));

        $video = Video::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'url' => $validated['url'],
            'thumbnail_url' => $validated['thumbnail_url'] ?? null,
            'category' => $validated['category'] ?? null,
            'duration_seconds' => $validated['duration_seconds'] ?? 0,
            'is_featured' => (bool) ($validated['is_featured'] ?? false),
            'tagged_product_ids' => $validated['tagged_product_ids'] ?? [],
            'status' => $validated['status'] ?? 'published',
        ]);

        return $this->success($video, 'Video created.', 201);
    }

    public function update(Request $request, Video $video): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'url' => ['sometimes', 'url', 'max:500'],
            'thumbnail_url' => ['nullable', 'url', 'max:500'],
            'category' => ['nullable', 'string', 'max:100'],
            'duration_seconds' => ['nullable', 'integer', 'min:0'],
            'is_featured' => ['nullable', 'boolean'],
            'tagged_product_ids' => ['nullable', 'array'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $video->update($validated);

        return $this->success($video->fresh(), 'Video updated.');
    }

    public function destroy(Video $video): JsonResponse
    {
        $video->delete();

        return $this->success(null, 'Video deleted.');
    }
}
