<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Refunds\ResolveAutomaticRefundRequest;
use App\Http\Resources\AdminRefundResource;
use App\Models\Payment\Refund;
use App\Services\Payment\AutomaticRefundOperationsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RefundController extends BaseController
{
    private const RELATIONS = ['user', 'order', 'payment', 'processor'];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:initiating,pending,processing,needs_attention,processed,failed'],
            'source' => ['sometimes', 'in:return,late_payment'],
            'provider' => ['sometimes', 'string', 'max:30'],
            'attention_only' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:100'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'sort' => ['sometimes', 'in:created_at,updated_at,amount,status,last_reconciled_at'],
            'direction' => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $search = isset($data['q']) ? '%'.$data['q'].'%' : null;
        $sort = $data['sort'] ?? 'created_at';
        $direction = $data['direction'] ?? 'desc';

        $page = Refund::query()
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['source'] ?? null, fn ($query, $source) => $query->where('source', $source))
            ->when($data['provider'] ?? null, fn ($query, $provider) => $query->where('provider', $provider))
            ->when($data['attention_only'] ?? false, fn ($query) => $query->where('status', 'needs_attention'))
            ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($search, fn ($query) => $query->where(function ($query) use ($search): void {
                $query->where('reference', 'like', $search)
                    ->orWhere('provider_reference', 'like', $search)
                    ->orWhere('provider_refund_id', 'like', $search)
                    ->orWhereHas('order', fn ($orders) => $orders->where('number', 'like', $search))
                    ->orWhereHas('user', fn ($users) => $users->where('email', 'like', $search));
            }))
            ->with(self::RELATIONS)
            ->orderBy($sort, $direction)
            ->orderBy('id', $direction)
            ->paginate($data['per_page'] ?? 20);

        return $this->success([
            'items' => AdminRefundResource::collection($page->getCollection()),
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

    public function show(Refund $refund): JsonResponse
    {
        return $this->success(new AdminRefundResource(
            $refund->load([...self::RELATIONS, 'events.actor']),
        ));
    }

    public function retry(
        Request $request,
        Refund $refund,
        AutomaticRefundOperationsService $operations,
    ): JsonResponse {
        $key = $this->idempotencyKey($request);
        $result = $operations->retry($refund, $request->user(), $key);

        return $this->success(
            new AdminRefundResource($result['refund']->load(self::RELATIONS)),
            $result['replayed']
                ? 'Refund retry already queued.'
                : ($result['mode'] === 'submission'
                    ? 'Refund submission queued for retry.'
                    : 'Refund reconciliation queued.'),
            $result['replayed'] ? 200 : 202,
        );
    }

    public function resolve(
        ResolveAutomaticRefundRequest $request,
        Refund $refund,
        AutomaticRefundOperationsService $operations,
    ): JsonResponse {
        $key = $this->idempotencyKey($request);
        $result = $operations->resolve($refund, $request->validated(), $request->user(), $key);

        return $this->success(
            new AdminRefundResource($result['refund']->load(self::RELATIONS)),
            $result['replayed'] ? 'Refund resolution already recorded.' : 'Refund resolved.',
        );
    }

    private function idempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');
        Validator::make(['idempotency_key' => $key], [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ])->validate();

        return (string) $key;
    }
}
