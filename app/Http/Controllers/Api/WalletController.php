<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Wallet\IndexWalletTransactionsRequest;
use App\Http\Resources\WalletTransactionResource;
use App\Models\Payment\Account;
use App\Services\Payment\WalletBalanceService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\JsonResponse;

class WalletController extends BaseController
{
    public function show(
        WalletBalanceService $balances,
        BusinessSettingsService $settings,
    ): JsonResponse {
        $data = $balances->forUser(auth('api')->user());
        $data['policy'] = [
            'deposits_enabled' => $settings->value('wallet.deposits_enabled'),
            'withdrawals_enabled' => $settings->value('wallet.withdrawals_enabled'),
            'minimum_deposit' => $balances->money($settings->value('wallet.minimum_deposit_minor')),
            'minimum_deposit_minor' => $settings->value('wallet.minimum_deposit_minor'),
            'maximum_deposit' => $balances->money($settings->value('wallet.maximum_deposit_minor')),
            'maximum_deposit_minor' => $settings->value('wallet.maximum_deposit_minor'),
        ];

        return $this->success($data);
    }

    public function transactions(IndexWalletTransactionsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $account = Account::query()->where('user_id', auth('api')->id())->first();
        $page = $account?->transactions()
            ->when($data['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when($data['balance_type'] ?? null, fn ($query, string $type) => $query->where('balance_type', $type))
            ->when($data['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($data['from'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '>=', $date))
            ->when($data['to'] ?? null, fn ($query, string $date) => $query->whereDate('created_at', '<=', $date))
            ->paginate($data['per_page'] ?? 20);

        if (! $page) {
            return $this->success([
                'items' => [],
                'pagination' => [
                    'current_page' => 1,
                    'per_page' => $data['per_page'] ?? 20,
                    'total' => 0,
                    'last_page' => 1,
                    'from' => null,
                    'to' => null,
                ],
            ]);
        }

        return $this->success([
            'items' => WalletTransactionResource::collection($page->getCollection()),
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
}
