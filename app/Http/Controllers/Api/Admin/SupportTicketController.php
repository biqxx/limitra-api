<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Support\IndexAdminSupportTicketsRequest;
use App\Http\Requests\Support\UpdateSupportTicketRequest;
use App\Http\Resources\SupportTicketResource;
use App\Models\Support\SupportTicket;
use App\Services\Settings\BusinessSettingsService;
use App\Services\Support\SupportTicketOperationsService;
use Illuminate\Http\JsonResponse;

class SupportTicketController extends BaseController
{
    public function index(
        IndexAdminSupportTicketsRequest $request,
        BusinessSettingsService $settings,
    ): JsonResponse {
        $data = $request->validated();
        $search = isset($data['q']) ? '%'.$data['q'].'%' : null;
        $now = now();
        $warningAt = $now->copy()->addMinutes((int) $settings->value('support.sla_warning_minutes'));

        $page = SupportTicket::query()
            ->when($data['queue'] ?? null, fn ($query, string $queue) => $query->where('category', $queue))
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($data['priority'] ?? null, fn ($query, string $priority) => $query->where('priority', $priority))
            ->when($data['assignee_id'] ?? null, fn ($query, int $id) => $query->where('assigned_to', $id))
            ->when($data['unassigned'] ?? false, fn ($query) => $query->whereNull('assigned_to'))
            ->when($search, fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('number', 'like', $search)
                    ->orWhere('subject', 'like', $search)
                    ->orWhere('contact_email', 'like', $search)
                    ->orWhereHas('order', fn ($orders) => $orders->where('number', 'like', $search));
            }))
            ->when(($data['sla'] ?? null) === 'overdue', fn ($query) => $query
                ->whereNotIn('status', ['resolved', 'closed'])
                ->where(function ($query) use ($now): void {
                    $query->where(function ($query) use ($now): void {
                        $query->whereNull('first_responded_at')->where('first_response_due_at', '<', $now);
                    })->orWhere('resolution_due_at', '<', $now);
                }))
            ->when(($data['sla'] ?? null) === 'due_soon', fn ($query) => $query
                ->whereNotIn('status', ['resolved', 'closed'])
                ->where(function ($query) use ($now, $warningAt): void {
                    $query->where(function ($query) use ($now, $warningAt): void {
                        $query->whereNull('first_responded_at')
                            ->whereBetween('first_response_due_at', [$now, $warningAt]);
                    })->orWhereBetween('resolution_due_at', [$now, $warningAt]);
                }))
            ->with(['order', 'assignee'])
            ->withCount('messages')
            ->latest('last_message_at')
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return $this->success([
            'items' => SupportTicketResource::collection($page->getCollection()),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    public function update(
        UpdateSupportTicketRequest $request,
        SupportTicket $supportTicket,
        SupportTicketOperationsService $operations,
    ): JsonResponse {
        return $this->success(
            new SupportTicketResource($operations->update(
                $supportTicket,
                $request->validated(),
                $request->user('api'),
            )),
            'Support ticket updated.',
        );
    }
}
