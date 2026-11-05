<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\BaseController;
use App\Models\Content\Video;
use App\Models\Content\VideoEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'duration_watched' => ['nullable', 'integer', 'min:0'],
            'session_id' => ['nullable', 'string', 'max:100'],
        ]);

        match ($validated['event_type']) {
            'play' => $video->increment('views_count'),
            'like' => $video->increment('likes_count'),
            'share' => $video->increment('shares_count'),
            default => null,
        };

        VideoEvent::create([
            'video_id' => $video->id,
            'user_id' => $request->user('api')?->id,
            'event_type' => $validated['event_type'],
            'duration_watched' => $validated['duration_watched'] ?? 0,
            'session_id' => $validated['session_id'] ?? null,
            'created_at' => now(),
        ]);

        return $this->success([
            'video_id' => $video->id,
            'event_type' => $validated['event_type'],
        ], 'Video event recorded.');
    }

    public function eventsBatch(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'events' => ['required', 'array', 'min:1', 'max:100'],
            'events.*.video_id' => ['required', 'integer', 'exists:videos,id'],
            'events.*.event_type' => ['required', 'string', 'in:impression,play,complete,like,share'],
            'events.*.duration_watched' => ['nullable', 'integer', 'min:0'],
            'events.*.session_id' => ['nullable', 'string', 'max:100'],
        ]);

        $now = now();
        $userId = $request->user('api')?->id;
        $increments = [];
        $rows = [];

        foreach ($validated['events'] as $event) {
            $vid = (int) $event['video_id'];
            $type = $event['event_type'];

            if (! isset($increments[$vid])) {
                $increments[$vid] = ['views' => 0, 'likes' => 0, 'shares' => 0];
            }

            if ($type === 'play') {
                $increments[$vid]['views']++;
            } elseif ($type === 'like') {
                $increments[$vid]['likes']++;
            } elseif ($type === 'share') {
                $increments[$vid]['shares']++;
            }

            $rows[] = [
                'video_id' => $vid,
                'user_id' => $userId,
                'event_type' => $type,
                'duration_watched' => $event['duration_watched'] ?? 0,
                'session_id' => $event['session_id'] ?? null,
                'created_at' => $now,
            ];
        }

        DB::transaction(function () use ($increments, $rows): void {
            foreach ($increments as $vid => $counts) {
                $updates = [];
                if ($counts['views'] > 0) {
                    $updates['views_count'] = DB::raw("views_count + {$counts['views']}");
                }
                if ($counts['likes'] > 0) {
                    $updates['likes_count'] = DB::raw("likes_count + {$counts['likes']}");
                }
                if ($counts['shares'] > 0) {
                    $updates['shares_count'] = DB::raw("shares_count + {$counts['shares']}");
                }

                if (! empty($updates)) {
                    Video::where('id', $vid)->update($updates);
                }
            }

            VideoEvent::insert($rows);
        });

        return $this->success([
            'processed' => count($rows),
        ], 'Video events batch ingested.', 202);
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

        ContentController::invalidateHomeCache();

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
        ContentController::invalidateHomeCache();

        return $this->success($video->fresh(), 'Video updated.');
    }

    public function destroy(Video $video): JsonResponse
    {
        $video->delete();
        ContentController::invalidateHomeCache();

        return $this->success(null, 'Video deleted.');
    }
}
