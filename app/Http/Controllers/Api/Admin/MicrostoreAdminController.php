<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Models\Microstore\Microstore;
use App\Services\Admin\AuditEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MicrostoreAdminController extends BaseController
{
    public function __construct(private readonly AuditEventService $auditEvents) {}

    public function index(Request $request): JsonResponse
    {
        $query = Microstore::query()->with('user:id,username,email')->latest('id');

        if ($request->filled('status')) {
            $query->where('status', (string) $request->input('status'));
        }

        if ($request->filled('moderation_status')) {
            $query->where('moderation_status', (string) $request->input('moderation_status'));
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

    public function show(Microstore $microstore): JsonResponse
    {
        return $this->success($microstore->load([
            'user:id,username,email',
            'moderatedBy:id,username,email',
            'curatedProducts.product',
        ]));
    }

    public function moderate(Request $request, Microstore $microstore): JsonResponse
    {
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

        return $this->success($microstore->fresh(), 'Microstore moderation status updated.');
    }

    public function toggleFeatured(Request $request, Microstore $microstore): JsonResponse
    {
        $isFeatured = $request->has('is_featured')
            ? $request->boolean('is_featured')
            : ! $microstore->is_featured;

        $microstore->update(['is_featured' => $isFeatured]);

        return $this->success($microstore->fresh(), 'Microstore featured status updated.');
    }
}
