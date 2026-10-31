<?php

namespace App\Http\Controllers\Api\Content;

use App\Http\Controllers\Api\BaseController;
use App\Models\Content\BlogPost;
use App\Models\Content\ContentRevision;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BlogController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $now = now();
        $query = BlogPost::query()
            ->with('author:id,username,email')
            ->where('status', 'published')
            ->where(function ($q) use ($now): void {
                $q->whereNull('published_at')->orWhere('published_at', '<=', $now);
            })
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
        $now = now();
        $post = BlogPost::query()
            ->with('author:id,username,email')
            ->where('slug', $slug)
            ->where('status', 'published')
            ->where(function ($q) use ($now): void {
                $q->whereNull('published_at')->orWhere('published_at', '<=', $now);
            })
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
            'scheduled_at' => $validated['scheduled_at'] ?? null,
            'published_at' => $publishedAt,
        ]);

        // Record initial revision
        ContentRevision::create([
            'revisionable_type' => BlogPost::class,
            'revisionable_id' => $post->id,
            'author_id' => $request->user('api')?->id,
            'title' => $post->title,
            'body' => $post->content,
            'summary' => 'Initial version',
            'metadata' => [
                'excerpt' => $post->excerpt,
                'category' => $post->category,
                'tags' => $post->tags,
                'cover_image_url' => $post->cover_image_url,
            ],
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
            'status' => ['nullable', 'in:draft,published,scheduled'],
            'scheduled_at' => ['nullable', 'date'],
            'change_summary' => ['nullable', 'string', 'max:255'],
        ]);

        // Save pre-update revision snapshot
        ContentRevision::create([
            'revisionable_type' => BlogPost::class,
            'revisionable_id' => $post->id,
            'author_id' => $request->user('api')?->id,
            'title' => $post->title,
            'body' => $post->content,
            'summary' => $validated['change_summary'] ?? 'Updated blog post',
            'metadata' => [
                'excerpt' => $post->excerpt,
                'category' => $post->category,
                'tags' => $post->tags,
                'cover_image_url' => $post->cover_image_url,
            ],
        ]);

        if (isset($validated['status']) && $validated['status'] === 'published' && ! $post->published_at) {
            $validated['published_at'] = now();
        }

        unset($validated['change_summary']);
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

    public function revisions(BlogPost $post): JsonResponse
    {
        $revisions = $post->revisions()->with('author:id,username,email')->paginate(20);

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

    public function restoreRevision(Request $request, BlogPost $post, ContentRevision $revision): JsonResponse
    {
        if ($revision->revisionable_id !== $post->id || $revision->revisionable_type !== BlogPost::class) {
            return $this->error('Revision does not belong to this blog post.', 404);
        }

        // Snapshot current state
        ContentRevision::create([
            'revisionable_type' => BlogPost::class,
            'revisionable_id' => $post->id,
            'author_id' => $request->user('api')?->id,
            'title' => $post->title,
            'body' => $post->content,
            'summary' => "Pre-restore snapshot before restoring revision #{$revision->id}",
            'metadata' => [
                'excerpt' => $post->excerpt,
                'category' => $post->category,
                'tags' => $post->tags,
                'cover_image_url' => $post->cover_image_url,
            ],
        ]);

        $post->update([
            'title' => $revision->title,
            'content' => $revision->body ?? $post->content,
            'excerpt' => $revision->metadata['excerpt'] ?? $post->excerpt,
            'category' => $revision->metadata['category'] ?? $post->category,
            'tags' => $revision->metadata['tags'] ?? $post->tags,
            'cover_image_url' => $revision->metadata['cover_image_url'] ?? $post->cover_image_url,
        ]);

        return $this->success($post->fresh(), 'Blog post restored from revision.');
    }
}
