<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\PaymentGatewayException;
use App\Http\Requests\Wallet\IndexWalletTransactionsRequest;
use App\Http\Resources\WalletTransactionResource;
use App\Models\Payment\Account;
use App\Models\Payment\WalletDeposit;
use App\Models\User;
use App\Services\Payment\PaystackService;
use App\Services\Payment\WalletBalanceService;
use App\Services\Payment\WalletLedgerService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

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
        PaystackService $paystack,
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

        $minMinor = (int) ($settings->value('wallet.minimum_deposit_minor') ?? 10000);
        $maxMinor = (int) ($settings->value('wallet.maximum_deposit_minor') ?? 50000000);

        if ($amountMinor < $minMinor) {
            throw ValidationException::withMessages([
                'amount' => ["The minimum deposit amount is {$balances->money($minMinor)}."],
            ]);
        }

        if ($amountMinor > $maxMinor) {
            throw ValidationException::withMessages([
                'amount' => ["The maximum deposit amount is {$balances->money($maxMinor)}."],
            ]);
        }

        /** @var User $user */
        $user = $request->user('api');
        $account = Account::firstOrCreate(
            ['user_id' => $user->id],
            ['currency' => 'NGN', 'balance' => 0]
        );

        $reference = 'WLT-DEP-'.strtoupper(Str::random(16));
        $method = $validated['method'] ?? 'card';

        try {
            $paystackResponse = $paystack->initializeTransaction([
                'amount' => $amountMinor,
                'email' => $user->email,
                'reference' => $reference,
                'callback_url' => $validated['callback_url'],
                'metadata' => [
                    'type' => 'wallet_deposit',
                    'user_id' => $user->id,
                    'account_id' => $account->id,
                    'amount_minor' => $amountMinor,
                ],
            ]);
        } catch (PaymentGatewayException $e) {
            return $this->error($e->getMessage(), 502);
        }

        $deposit = WalletDeposit::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'reference' => $reference,
            'amount_minor' => $amountMinor,
            'currency' => $account->currency,
            'status' => 'pending',
            'method' => $method,
            'authorization_url' => $paystackResponse['authorization_url'] ?? $paystackResponse['data']['authorization_url'] ?? null,
            'access_code' => $paystackResponse['access_code'] ?? $paystackResponse['data']['access_code'] ?? null,
            'metadata' => [
                'callback_url' => $validated['callback_url'],
            ],
        ]);

        return $this->success([
            'deposit' => [
                'id' => $deposit->id,
                'reference' => $deposit->reference,
                'amount' => $balances->money($amountMinor),
                'amount_minor' => $amountMinor,
                'currency' => $deposit->currency,
                'status' => $deposit->status,
                'authorization_url' => $deposit->authorization_url,
                'access_code' => $deposit->access_code,
                'created_at' => $deposit->created_at->toIso8601String(),
            ],
        ], 'Deposit initialized successfully.', 201);
    }

    public function verifyDeposit(
        string $reference,
        PaystackService $paystack,
        WalletLedgerService $ledger,
        WalletBalanceService $balances,
    ): JsonResponse {
        $deposit = WalletDeposit::where('reference', $reference)
            ->where('user_id', auth('api')->id())
            ->firstOrFail();

        if ($deposit->status === 'completed') {
            return $this->success([
                'deposit' => $deposit,
                'balance' => $balances->forUser($deposit->user),
            ], 'Deposit already verified.');
        }

        try {
            $verified = $paystack->verifyTransaction($reference);
        } catch (PaymentGatewayException $e) {
            return $this->error($e->getMessage(), 502);
        }

        $status = $verified['status'] ?? $verified['data']['status'] ?? null;
        if ($status !== 'success') {
            return $this->error('The payment has not been confirmed by the gateway.', 400);
        }

        DB::transaction(function () use ($deposit, $verified, $ledger): void {
            /** @var WalletDeposit $locked */
            $locked = WalletDeposit::whereKey($deposit->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === 'completed') {
                return;
            }

            $providerRef = (string) ($verified['id'] ?? $verified['data']['id'] ?? $verified['reference'] ?? '');

            $locked->update([
                'status' => 'completed',
                'paid_at' => now(),
                'provider_reference' => $providerRef,
            ]);

            $ledger->credit(
                $locked->user,
                $locked->amount_minor,
                'cash',
                'deposit',
                "wallet_deposit:{$locked->id}",
                "Wallet deposit via {$locked->method}",
                'wallet_deposit',
                $locked->id,
                ['reference' => $locked->reference]
            );
        }, 3);

        return $this->success([
            'deposit' => $deposit->fresh(),
            'balance' => $balances->forUser($deposit->user),
        ], 'Deposit verified and credited successfully.');
    }
}
