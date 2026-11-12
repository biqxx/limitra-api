<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\BaseController;
use App\Models\Commerce\Promotion;
use App\Models\Content\ContentEntry;
use App\Models\Content\ContentRevision;
use App\Models\Content\Video;
use App\Models\Product\Category;
use App\Models\Product\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ContentController extends BaseController
{
    public function show(string $page): JsonResponse
    {
        $now = now();
        $entry = ContentEntry::query()
            ->where('slug', $page)
            ->where('status', 'published')
            ->where(function ($q) use ($now): void {
                $q->whereNull('published_at')->orWhere('published_at', '<=', $now);
            })
            ->firstOrFail();

        return $this->success($entry);
    }

    public function home(Request $request): JsonResponse
    {
        $channel = (string) $request->input('channel', 'web');
        $version = (string) Cache::get('storefront:home:version', '1');
        $cacheKey = "storefront:home:v{$version}:{$channel}";

        $payload = Cache::remember($cacheKey, 1800, function () use ($channel): array {
            $now = now();
            $featuredCategories = Category::query()
                ->whereNull('parent_id')
                ->where('active', true)
                ->orderBy('sort_order')
                ->orderBy('name')
                ->limit(6)
                ->get(['id', 'name', 'slug']);

            $featuredProducts = Product::query()->where('status', 'active')->where('is_featured', true)->limit(8)->get();
            if ($featuredProducts->isEmpty()) {
                $featuredProducts = Product::query()->where('status', 'active')->latest('id')->limit(8)->get();
            }

            $activePromotions = Promotion::query()
                ->where('active', true)
                ->where(function ($q) use ($now): void {
                    $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
                })
                ->where(function ($q) use ($now): void {
                    $q->whereNull('ends_at')->orWhere('ends_at', '>', $now);
                })
                ->limit(3)
                ->get(['id', 'code', 'name', 'type', 'value', 'starts_at', 'ends_at']);

            $featuredVideos = Video::query()->where('status', 'published')->where('is_featured', true)->limit(4)->get();

            return [
                'channel' => $channel,
                'categories' => $featuredCategories,
                'featured_products' => $featuredProducts,
                'promotions' => $activePromotions,
                'featured_videos' => $featuredVideos,
            ];
        });

        return $this->success($payload, 'Storefront homepage payload retrieved.');
    }

    public static function invalidateHomeCache(): void
    {
        Cache::forever('storefront:home:version', (string) Str::uuid());
    }

    public function index(Request $request): JsonResponse
    {
        $query = ContentEntry::query()->latest('id');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
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

    public function adminShow(ContentEntry $entry): JsonResponse
    {
        return $this->success($entry);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:100', 'unique:content_entries,slug'],
            'channel' => ['nullable', 'string', 'max:30'],
            'locale' => ['nullable', 'string', 'max:10'],
            'body' => ['nullable', 'string'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'in:draft,published,scheduled'],
            'scheduled_at' => ['nullable', 'date'],
        ]);

        $slug = ! empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['title']);
        $status = $validated['status'] ?? 'draft';

        $publishedAt = null;
        if ($status === 'published') {
            $publishedAt = now();
        } elseif ($status === 'scheduled') {
            $publishedAt = ! empty($validated['scheduled_at']) ? $validated['scheduled_at'] : now();
        }

        $entry = ContentEntry::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'channel' => $validated['channel'] ?? 'web',
            'locale' => $validated['locale'] ?? 'en',
            'body' => $validated['body'] ?? null,
            'seo_title' => $validated['seo_title'] ?? null,
            'seo_description' => $validated['seo_description'] ?? null,
            'status' => $status,
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'published_at' => $publishedAt,
        ]);

        // Record initial revision
        ContentRevision::create([
            'revisionable_type' => ContentEntry::class,
            'revisionable_id' => $entry->id,
            'author_id' => $request->user('api')?->id,
            'title' => $entry->title,
            'body' => $entry->body,
            'summary' => 'Initial version',
            'metadata' => [
                'slug' => $entry->slug,
                'channel' => $entry->channel,
                'seo_title' => $entry->seo_title,
                'seo_description' => $entry->seo_description,
            ],
        ]);

        self::invalidateHomeCache();

        return $this->success($entry, 'Content entry created.', 201);
    }

    public function update(Request $request, ContentEntry $entry): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'in:draft,published,scheduled'],
            'scheduled_at' => ['nullable', 'date'],
            'change_summary' => ['nullable', 'string', 'max:255'],
        ]);

        // Save pre-update revision snapshot
        ContentRevision::create([
            'revisionable_type' => ContentEntry::class,
            'revisionable_id' => $entry->id,
            'author_id' => $request->user('api')?->id,
            'title' => $entry->title,
            'body' => $entry->body,
            'summary' => $validated['change_summary'] ?? 'Updated content entry',
            'metadata' => [
                'slug' => $entry->slug,
                'channel' => $entry->channel,
                'seo_title' => $entry->seo_title,
                'seo_description' => $entry->seo_description,
            ],
        ]);

        if (isset($validated['status']) && $validated['status'] === 'published' && ! $entry->published_at) {
            $validated['published_at'] = now();
        }

        unset($validated['change_summary']);
        $entry->update($validated);
        self::invalidateHomeCache();

        return $this->success($entry->fresh(), 'Content entry updated.');
    }

    public function publish(ContentEntry $entry): JsonResponse
    {
        $entry->update([
            'status' => 'published',
            'published_at' => now(),
        ]);
        self::invalidateHomeCache();

        return $this->success($entry->fresh(), 'Content entry published.');
    }

    public function revisions(ContentEntry $entry): JsonResponse
    {
        $revisions = $entry->revisions()->with('author:id,username,email')->paginate(20);

        return $this->success([
            'items' => $revisions->items(),
            'pagination' => [
                'current_page' => $revisions->currentPage(),
                'per_page' => $revisions->perPage(),
                'total' => $revisions->total(),
                'last_page' => $revisions->lastPage(),
                'from' => $revisions->firstItem(),
                'to' => $revisions->lastItem(),
            ],
        ]);
    }

    public function restoreRevision(Request $request, ContentEntry $entry, ContentRevision $revision): JsonResponse
    {
        if ($revision->revisionable_id !== $entry->id || $revision->revisionable_type !== ContentEntry::class) {
            return $this->error('Revision does not belong to this content entry.', 404);
        }

        // Snapshot current state
        ContentRevision::create([
            'revisionable_type' => ContentEntry::class,
            'revisionable_id' => $entry->id,
            'author_id' => $request->user('api')?->id,
            'title' => $entry->title,
            'body' => $entry->body,
            'summary' => "Pre-restore snapshot before restoring revision #{$revision->id}",
            'metadata' => [
                'slug' => $entry->slug,
                'seo_title' => $entry->seo_title,
                'seo_description' => $entry->seo_description,
            ],
        ]);

        $entry->update([
            'title' => $revision->title,
            'body' => $revision->body,
            'seo_title' => $revision->metadata['seo_title'] ?? $entry->seo_title,
            'seo_description' => $revision->metadata['seo_description'] ?? $entry->seo_description,
        ]);
        self::invalidateHomeCache();

        return $this->success($entry->fresh(), 'Content entry restored from revision.');
    }
}
