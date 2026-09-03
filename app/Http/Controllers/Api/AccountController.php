<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Wallet\ManualWalletAdjustmentRequest;
use App\Http\Resources\AccountResource;
use App\Models\Payment\Account;
use App\Services\Payment\WalletLedgerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use OpenApi\Attributes as OA;

class AccountController extends BaseController
{
    #[OA\Get(
        path: '/accounts',
        tags: ['Accounts'],
        summary: 'List accounts (staff/admin)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'user_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Account list', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Account::class);

        $accounts = Account::with('user')
            ->when($request->user_id, fn ($q) => $q->where('user_id', $request->user_id))
            ->orderByDesc('id')
            ->get();

        return $this->success(AccountResource::collection($accounts));
    }

    #[OA\Post(
        path: '/accounts',
        tags: ['Accounts'],
        summary: 'Create account (admin)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['user_id'],
                properties: [
                    new OA\Property(property: 'user_id', type: 'integer'),
                    new OA\Property(property: 'balance', type: 'number', format: 'float', minimum: 0, nullable: true),
                    new OA\Property(property: 'bonus_balance', type: 'number', format: 'float', minimum: 0, nullable: true),
                    new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3, nullable: true, example: 'USD'),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Account created', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'User already has an account', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Account::class);

        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id', 'unique:accounts,user_id'],
            'balance' => ['prohibited'],
            'bonus_balance' => ['prohibited'],
            'currency' => ['nullable', 'string', 'size:3'],
        ]);

        $account = Account::create($data);

        return $this->success(new AccountResource($account), 'Account created.', 201);
    }

    #[OA\Get(
        path: '/accounts/{account}',
        tags: ['Accounts'],
        summary: 'Get account',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Account details', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(Account $account): JsonResponse
    {
        $this->authorize('view', $account);

        return $this->success(new AccountResource($account->load('user')));
    }

    #[OA\Put(
        path: '/accounts/{account}',
        tags: ['Accounts'],
        summary: 'Update account (admin)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'currency', type: 'string', minLength: 3, maxLength: 3),
                    new OA\Property(property: 'bonus_balance', type: 'number', format: 'float', minimum: 0),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Account updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(Request $request, Account $account): JsonResponse
    {
        $this->authorize('update', $account);

        $data = $request->validate([
            'currency' => ['sometimes', 'required', 'string', 'size:3'],
            'bonus_balance' => ['prohibited'],
        ]);

        $account->update($data);

        return $this->success(new AccountResource($account), 'Account updated.');
    }

    #[OA\Delete(
        path: '/accounts/{account}',
        tags: ['Accounts'],
        summary: 'Delete account',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Account deleted', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function destroy(Account $account): JsonResponse
    {
        $this->authorize('delete', $account);

        $account->delete();

        return $this->success(message: 'Account deleted.');
    }

    #[OA\Post(
        path: '/accounts/{account}/deposit',
        tags: ['Accounts'],
        summary: 'Deposit to account',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount'],
                properties: [
                    new OA\Property(property: 'amount', type: 'number', format: 'float', minimum: 0.01),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Deposit successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function deposit(
        ManualWalletAdjustmentRequest $request,
        Account $account,
        WalletLedgerService $ledger,
    ): JsonResponse {
        $this->authorize('deposit', $account);

        $data = $request->validated();
        $ledger->credit(
            $account->user,
            $this->minor($data['amount']),
            $data['balance_type'] ?? 'cash',
            'adjustment',
            'manual-credit:'.$account->id.':'.$data['idempotency_key'],
            $data['reason'],
            'admin',
            (int) auth('api')->id(),
        );

        return $this->success(new AccountResource($account->refresh()), 'Wallet credit recorded.');
    }

    #[OA\Post(
        path: '/accounts/{account}/withdraw',
        tags: ['Accounts'],
        summary: 'Withdraw from account',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'account', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['amount'],
                properties: [
                    new OA\Property(property: 'amount', type: 'number', format: 'float', minimum: 0.01),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Withdrawal successful', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 422, description: 'Insufficient balance', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function withdraw(
        ManualWalletAdjustmentRequest $request,
        Account $account,
        WalletLedgerService $ledger,
    ): JsonResponse {
        $this->authorize('withdraw', $account);

        $data = $request->validated();
        $ledger->debit(
            $account->user,
            $this->minor($data['amount']),
            $data['balance_type'] ?? 'cash',
            'adjustment',
            'manual-debit:'.$account->id.':'.$data['idempotency_key'],
            $data['reason'],
            'admin',
            (int) auth('api')->id(),
        );

        return $this->success(new AccountResource($account->refresh()), 'Wallet debit recorded.');
    }

    private function minor(mixed $amount): int
    {
        return (int) round((float) $amount * 100);
    }
}
