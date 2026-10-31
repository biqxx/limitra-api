<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\AuditEventResource;
use App\Models\Admin\AuditEvent;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminActivityController extends BaseController
{
    /**
     * Display a paginated listing of system audit activity.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AuditEvent::query()->with('actor');

        if ($request->filled('actor_id')) {
            $query->where('actor_id', $request->integer('actor_id'));
        }

        if ($request->filled('action')) {
            $query->where('action', $request->string('action'));
        }

        if ($request->filled('subject_type')) {
            $query->where('subject_type', $request->string('subject_type'));
        }

        if ($request->filled('subject_id')) {
            $query->where('subject_id', $request->integer('subject_id'));
        }

        if ($request->filled('from')) {
            $from = Carbon::parse($request->string('from'))->startOfDay();
            $query->where('created_at', '>=', $from);
        }

        if ($request->filled('to')) {
            $to = Carbon::parse($request->string('to'))->endOfDay();
            $query->where('created_at', '<=', $to);
        }

        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($term): void {
                $q->where('action', 'like', $term)
                    ->orWhere('reason', 'like', $term)
                    ->orWhere('request_id', 'like', $term);
            });
        }

        $perPage = min(max((int) $request->input('per_page', 20), 1), 100);
        $events = $query->latest('id')->paginate($perPage);

        return $this->success([
            'items' => AuditEventResource::collection($events->getCollection()),
            'pagination' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
        ], 'Audit activity log retrieved.');
    }

    /**
     * Display the specified audit event.
     */
    public function show(AuditEvent $event): JsonResponse
    {
        $event->load('actor');

        return $this->success(
            new AuditEventResource($event),
            'Audit event details retrieved.'
        );
    }
}
