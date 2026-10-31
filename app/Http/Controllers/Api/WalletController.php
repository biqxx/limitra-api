<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentGatewayException;
use App\Http\Requests\Wallet\IndexWalletTransactionsRequest;
use App\Http\Resources\WalletTransactionResource;
use App\Models\Payment\Account;
use App\Models\Payment\WalletDeposit;
use App\Services\Payment\WalletBalanceService;
use App\Services\Settings\BusinessSettingsService;
use App\Services\Wallet\WalletDepositSettlementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function initializeDeposit(
        Request $request,
        WalletBalanceService $balances,
        BusinessSettingsService $settings,
        WalletDepositSettlementService $settlement,
    ): JsonResponse {
        if (! $settings->value('wallet.deposits_enabled', true)) {
            return $this->error('Wallet deposits are currently disabled.', 403, null, 'WALLET_DEPOSITS_DISABLED');
        }

        $validated = $request->validate([
            'amount' => ['required_without:amount_minor', 'nullable', 'numeric', 'min:0.01'],
            'amount_minor' => ['required_without:amount', 'nullable', 'integer', 'min:1'],
            'method' => ['sometimes', 'string', 'in:card,bank_transfer'],
            'callback_url' => ['required', 'url:http,https', 'max:2000'],
        ]);

        $amountMinor = ! empty($validated['amount'])
            ? (int) round(((float) $validated['amount']) * 100)
            : (int) $validated['amount_minor'];

        $method = $validated['method'] ?? 'card';
        $idempotencyKey = $request->header('Idempotency-Key');

        try {
            $result = $settlement->initialize(
                user: $request->user('api'),
                amountMinor: $amountMinor,
                method: $method,
                callbackUrl: $validated['callback_url'],
                idempotencyKey: $idempotencyKey
            );
        } catch (PaymentGatewayException $e) {
            return $this->error($e->getMessage(), 502);
        }

        $deposit = $result['deposit'];
        $status = $result['replayed'] ? 200 : 201;
        $message = $result['replayed'] ? 'Deposit initialization replayed.' : 'Deposit initialized successfully.';

        return $this->success([
            'deposit' => [
                'id' => $deposit->id,
                'reference' => $deposit->reference,
                'amount' => $balances->money($deposit->amount_minor),
                'amount_minor' => $deposit->amount_minor,
                'currency' => $deposit->currency,
                'status' => $deposit->status,
                'authorization_url' => $deposit->authorization_url,
                'access_code' => $deposit->access_code,
                'created_at' => $deposit->created_at->toIso8601String(),
            ],
        ], $message, $status);
    }

    public function verifyDeposit(
        string $reference,
        WalletDepositSettlementService $settlement,
        WalletBalanceService $balances,
    ): JsonResponse {
        $deposit = WalletDeposit::where('reference', $reference)
            ->where('user_id', auth('api')->id())
            ->firstOrFail();

        $result = $settlement->reconcileStatus($deposit);
        $freshDeposit = $result['deposit'];

        return $this->success([
            'deposit' => $freshDeposit,
            'balance' => $balances->forUser($deposit->user),
        ], $result['message']);
    }
}
