<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\BaseController;
use App\Models\Content\BlogPost;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BlogController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = BlogPost::query()
            ->with('author:id,username,email')
            ->where('status', 'published')
            ->latest('published_at');

        if ($request->filled('category')) {
            $query->where('category', (string) $request->input('category'));
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

    public function show(string $slug): JsonResponse
    {
        $post = BlogPost::query()
            ->with('author:id,username,email')
            ->where('slug', $slug)
            ->where('status', 'published')
            ->firstOrFail();

        return $this->success($post);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $query = BlogPost::query()->with('author:id,username,email')->latest('id');

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

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:150', 'unique:blog_posts,slug'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['required', 'string'],
            'cover_image_url' => ['nullable', 'url', 'max:500'],
            'category' => ['nullable', 'string', 'max:100'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        $slug = ! empty($validated['slug']) ? Str::slug($validated['slug']) : Str::slug($validated['title']);
        $status = $validated['status'] ?? 'draft';

        $post = BlogPost::create([
            'title' => $validated['title'],
            'slug' => $slug,
            'author_id' => $request->user('api')?->id,
            'excerpt' => $validated['excerpt'] ?? null,
            'content' => $validated['content'],
            'cover_image_url' => $validated['cover_image_url'] ?? null,
            'category' => $validated['category'] ?? null,
            'tags' => $validated['tags'] ?? [],
            'status' => $status,
            'published_at' => $status === 'published' ? now() : null,
        ]);

        return $this->success($post, 'Blog post created.', 201);
    }

    public function update(Request $request, BlogPost $post): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => ['sometimes', 'string'],
            'cover_image_url' => ['nullable', 'url', 'max:500'],
            'category' => ['nullable', 'string', 'max:100'],
            'tags' => ['nullable', 'array'],
            'status' => ['nullable', 'in:draft,published'],
        ]);

        if (isset($validated['status']) && $validated['status'] === 'published' && ! $post->published_at) {
            $validated['published_at'] = now();
        }

        $post->update($validated);

        return $this->success($post->fresh(), 'Blog post updated.');
    }

    public function publish(BlogPost $post): JsonResponse
    {
        $post->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return $this->success($post->fresh(), 'Blog post published.');
    }

    public function destroy(BlogPost $post): JsonResponse
    {
        $post->delete();

        return $this->success(null, 'Blog post deleted.');
    }
}
