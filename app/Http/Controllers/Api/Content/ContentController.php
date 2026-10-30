<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\BaseController;
use App\Models\Commerce\Promotion;
use App\Models\Content\ContentEntry;
use App\Models\Content\Video;
use App\Models\Product\Category;
use App\Models\Product\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ContentController extends BaseController
{
    public function show(string $page): JsonResponse
    {
        $entry = ContentEntry::query()
            ->where('slug', $page)
            ->where('status', 'published')
            ->firstOrFail();

        return $this->success($entry);
    }

    public function home(Request $request): JsonResponse
    {
        $featuredCategories = Category::query()->limit(6)->get(['id', 'name', 'slug']);
        $featuredProducts = Product::query()->where('status', 'active')->where('is_featured', true)->limit(8)->get();
        if ($featuredProducts->isEmpty()) {
            $featuredProducts = Product::query()->where('status', 'active')->latest('id')->limit(8)->get();
        }

        $activePromotions = Promotion::query()->where('active', true)->limit(3)->get(['id', 'code', 'name', 'type', 'value']);
        $featuredVideos = Video::query()->where('status', 'published')->where('is_featured', true)->limit(4)->get();

        return $this->success([
            'channel' => $request->input('channel', 'web'),
            'categories' => $featuredCategories,
            'featured_products' => $featuredProducts,
            'promotions' => $activePromotions,
            'featured_videos' => $featuredVideos,
        ], 'Storefront homepage payload retrieved.');
    }

    public function index(Request $request): JsonResponse
    {
        $paginator = ContentEntry::query()->latest('id')->paginate($request->integer('per_page', 20));

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
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $slug = ! empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['title']);
        $status = $validated['status'] ?? 'draft';

        $entry = ContentEntry::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'channel' => $validated['channel'] ?? 'web',
            'locale' => $validated['locale'] ?? 'en',
            'body' => $validated['body'] ?? null,
            'seo_title' => $validated['seo_title'] ?? null,
            'seo_description' => $validated['seo_description'] ?? null,
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);

        return $this->success($entry, 'Content entry created.', 201);
    }

    public function update(Request $request, ContentEntry $entry): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'body' => ['nullable', 'string'],
            'seo_title' => ['nullable', 'string', 'max:255'],
            'seo_description' => ['nullable', 'string', 'max:500'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        if (isset($validated['status']) && $validated['status'] === 'published' && ! $entry->published_at) {
            $validated['published_at'] = now();
        }

        $entry->update($validated);

        return $this->success($entry->fresh(), 'Content entry updated.');
    }

    public function publish(ContentEntry $entry): JsonResponse
    {
        $entry->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return $this->success($entry->fresh(), 'Content entry published.');
    }
}
