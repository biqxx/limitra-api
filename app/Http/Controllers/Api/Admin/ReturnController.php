<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\PaymentGatewayException;
use App\Http\Controllers\Api\BaseController;
use App\Http\Requests\Returns\CreateRefundRequest;
use App\Http\Requests\Returns\UpdateReturnRequest;
use App\Http\Resources\RefundResource;
use App\Http\Resources\ReturnRequestResource;
use App\Models\Order\ReturnRequest;
use App\Services\Order\ReturnService;
use App\Services\Payment\RefundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ReturnController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', 'in:pending,approved,rejected,cancelled,received,completed'],
            'resolution' => ['sometimes', 'in:refund,store_credit,replacement'],
            'q' => ['sometimes', 'string', 'max:100'],
            'from' => ['sometimes', 'date'],
            'to' => ['sometimes', 'date', 'after_or_equal:from'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $search = isset($data['q']) ? '%'.$data['q'].'%' : null;
        $page = ReturnRequest::query()
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['resolution'] ?? null, fn ($query, $resolution) => $query->where('resolution', $resolution))
            ->when($data['from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('number', 'like', $search)
                    ->orWhereHas('order', fn ($orders) => $orders->where('number', 'like', $search));
            }))
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

    public function update(
        UpdateReturnRequest $request,
        ReturnRequest $returnRequest,
        ReturnService $returns,
    ): JsonResponse {
        return $this->success(
            new ReturnRequestResource($returns->transition($returnRequest, $request->validated(), (int) auth('api')->id())),
            'Return request updated.',
        );
    }

    public function refund(
        CreateRefundRequest $request,
        ReturnRequest $returnRequest,
        RefundService $refunds,
    ): JsonResponse {
        $key = $request->header('Idempotency-Key');
        Validator::make(['idempotency_key' => $key], [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:100'],
        ])->validate();

        try {
            $result = $refunds->create(
                $returnRequest,
                $request->validated(),
                (int) auth('api')->id(),
                (string) $key,
            );
        } catch (PaymentGatewayException $exception) {
            return $this->error($exception->getMessage(), 502);
        }

        return $this->success(
            new RefundResource($result['refund']),
            $result['replayed'] ? 'Refund request already submitted.' : 'Refund submitted to the payment provider.',
            $result['replayed'] ? 200 : 201,
        );
    }
}
