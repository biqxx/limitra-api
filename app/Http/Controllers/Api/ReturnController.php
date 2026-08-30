<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Returns\StoreReturnRequest;
use App\Http\Resources\ReturnRequestResource;
use App\Models\Order\Order;
use App\Models\Order\ReturnRequest as ReturnRequestModel;
use App\Services\Order\ReturnService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReturnController extends BaseController
{
    public function index(Request $request, ReturnService $returns): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:pending,approved,rejected,cancelled,received,completed'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $page = ReturnRequestModel::query()
            ->where('user_id', auth('api')->id())
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->with(['items.orderItem', 'images', 'refunds'])
            ->latest('id')
            ->paginate($data['per_page'] ?? 20);

        return $this->success([
            'items' => ReturnRequestResource::collection($page->getCollection()),
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

    public function store(
        StoreReturnRequest $request,
        Order $order,
        ReturnService $returns,
    ): JsonResponse {
        $returnRequest = $returns->create(
            $order,
            $request->validated(),
            (int) auth('api')->id(),
            $request->file('images', []),
        );

        return $this->success(
            new ReturnRequestResource($returnRequest),
            'Return request submitted for approval.',
            201,
        );
    }

    public function show(ReturnRequestModel $returnRequest, ReturnService $returns): JsonResponse
    {
        $this->authorizeView($returnRequest);

        return $this->success(new ReturnRequestResource($returnRequest->load($returns->relations())));
    }

    public function cancel(ReturnRequestModel $returnRequest, ReturnService $returns): JsonResponse
    {
        if ($returnRequest->user_id !== auth('api')->id()) {
            abort(403, 'Forbidden.');
        }

        return $this->success(
            new ReturnRequestResource($returns->cancel($returnRequest, (int) auth('api')->id())),
            'Return request cancelled.',
        );
    }

    private function authorizeView(ReturnRequestModel $returnRequest): void
    {
        $user = auth('api')->user();
        if ($returnRequest->user_id !== $user->id && ! $user->isStaff()) {
            abort(403, 'Forbidden.');
        }
    }
}
