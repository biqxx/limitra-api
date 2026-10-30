<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\AuditEventResource;
use App\Models\Admin\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class ActivityLogController extends BaseController
{
    #[OA\Get(
        path: '/admin/activity',
        tags: ['Admin - Activity Logs'],
        summary: 'List audit activity log entries',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'action', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'actor_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'from_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'to_date', in: 'query', required: false, schema: new OA\Schema(type: 'string', format: 'date')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 20)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated activity log items', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['sometimes', 'string', 'max:100'],
            'actor_id' => ['sometimes', 'integer', 'exists:users,id'],
            'from_date' => ['sometimes', 'date'],
            'to_date' => ['sometimes', 'date', 'after_or_equal:from_date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = AuditEvent::query()->with('actor')
            ->when($request->action, fn ($q) => $q->where('action', $request->action))
            ->when($request->actor_id, fn ($q) => $q->where('actor_id', $request->actor_id))
            ->when($request->from_date, fn ($q) => $q->whereDate('created_at', '>=', $request->from_date))
            ->when($request->to_date, fn ($q) => $q->whereDate('created_at', '<=', $request->to_date))
            ->orderByDesc('id');

        $events = $query->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => AuditEventResource::collection($events->getCollection()),
            'pagination' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
                'from' => $events->firstItem(),
                'to' => $events->lastItem(),
            ],
        ]);
    }

    #[OA\Get(
        path: '/admin/activity/{activity}',
        tags: ['Admin - Activity Logs'],
        summary: 'Get single audit activity entry',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'activity', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Audit event detail', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(AuditEvent $activity): JsonResponse
    {
        return $this->success(new AuditEventResource($activity->load('actor')));
    }
}
