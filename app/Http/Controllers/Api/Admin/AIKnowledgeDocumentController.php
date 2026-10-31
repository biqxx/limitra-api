<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\AI\KnowledgeDocument;
use App\Services\Admin\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AIKnowledgeDocumentController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $query = KnowledgeDocument::query();

        if ($request->filled('status')) {
            $query->where('status', $request->string('status')->toString());
        }

        if ($request->filled('category')) {
            $query->where('category', $request->string('category')->toString());
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search')->toString().'%';
            $query->where(function ($sub) use ($search): void {
                $sub->where('title', 'like', $search)
                    ->orWhere('slug', 'like', $search)
                    ->orWhere('content', 'like', $search);
            });
        }

        $perPage = min(max($request->integer('per_page', 15), 1), 100);
        $documents = $query->latest('updated_at')->paginate($perPage);

        return $this->success([
            'items' => $documents->items(),
            'pagination' => [
                'current_page' => $documents->currentPage(),
                'per_page' => $documents->perPage(),
                'total' => $documents->total(),
                'last_page' => $documents->lastPage(),
                'from' => $documents->firstItem(),
                'to' => $documents->lastItem(),
            ],
        ], 'AI knowledge documents retrieved successfully.');
    }

    public function store(Request $request, AuditEventService $audits): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:ai_knowledge_documents,slug'],
            'category' => ['nullable', 'string', 'max:100'],
            'content' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $title = $validated['title'];
        $slug = $validated['slug'] ?? Str::slug($title);
        $baseSlug = $slug;
        $counter = 1;
        while (KnowledgeDocument::query()->where('slug', $slug)->exists()) {
            $slug = "{$baseSlug}-{$counter}";
            $counter++;
        }

        $content = $validated['content'];
        $tokenCount = (int) ceil(mb_strlen($content) / 4);

        $doc = KnowledgeDocument::query()->create([
            'title' => $title,
            'slug' => $slug,
            'category' => $validated['category'] ?? 'general',
            'version' => 1,
            'content' => $content,
            'status' => KnowledgeDocument::STATUS_DRAFT,
            'token_count' => $tokenCount,
            'metadata' => $validated['metadata'] ?? null,
        ]);

        $audits->record(
            action: 'ai_knowledge.created',
            actor: $request->user('api'),
            subject: $doc,
            before: null,
            after: [
                'title' => $doc->title,
                'slug' => $doc->slug,
                'version' => $doc->version,
                'status' => $doc->status,
            ],
            metadata: ['category' => $doc->category],
            request: $request,
        );

        return $this->success($doc, 'AI knowledge document created successfully.', 201);
    }

    public function show(KnowledgeDocument $document): JsonResponse
    {
        return $this->success($document, 'AI knowledge document retrieved successfully.');
    }

    public function update(
        Request $request,
        KnowledgeDocument $document,
        AuditEventService $audits,
    ): JsonResponse {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('ai_knowledge_documents', 'slug')->ignore($document->id)],
            'category' => ['sometimes', 'string', 'max:100'],
            'content' => ['sometimes', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $before = [
            'title' => $document->title,
            'slug' => $document->slug,
            'version' => $document->version,
            'status' => $document->status,
            'category' => $document->category,
        ];

        $contentChanged = isset($validated['content']) && $validated['content'] !== $document->content;

        if ($contentChanged) {
            $document->version += 1;
            $document->token_count = (int) ceil(mb_strlen($validated['content']) / 4);
            $document->status = KnowledgeDocument::STATUS_DRAFT;
            $document->indexed_at = null;
        }

        $document->fill($validated);
        $document->save();

        $audits->record(
            action: 'ai_knowledge.updated',
            actor: $request->user('api'),
            subject: $document,
            before: $before,
            after: [
                'title' => $document->title,
                'slug' => $document->slug,
                'version' => $document->version,
                'status' => $document->status,
                'category' => $document->category,
            ],
            metadata: ['content_changed' => $contentChanged],
            request: $request,
        );

        return $this->success($document, 'AI knowledge document updated successfully.');
    }

    public function indexDocument(
        Request $request,
        KnowledgeDocument $document,
        AuditEventService $audits,
    ): JsonResponse {
        $document->status = KnowledgeDocument::STATUS_INDEXED;
        $document->indexed_at = now();
        $document->save();

        $audits->record(
            action: 'ai_knowledge.indexed',
            actor: $request->user('api'),
            subject: $document,
            before: ['status' => KnowledgeDocument::STATUS_DRAFT],
            after: ['status' => KnowledgeDocument::STATUS_INDEXED, 'indexed_at' => $document->indexed_at->toIso8601String()],
            metadata: ['version' => $document->version],
            request: $request,
        );

        return $this->success($document, 'AI knowledge document indexed successfully.');
    }

    public function destroy(
        Request $request,
        KnowledgeDocument $document,
        AuditEventService $audits,
    ): JsonResponse {
        $audits->record(
            action: 'ai_knowledge.deleted',
            actor: $request->user('api'),
            subject: $document,
            before: [
                'id' => $document->id,
                'title' => $document->title,
                'slug' => $document->slug,
            ],
            after: null,
            metadata: ['version' => $document->version],
            request: $request,
        );

        $document->delete();

        return $this->success(null, 'AI knowledge document deleted successfully.');
    }
}
