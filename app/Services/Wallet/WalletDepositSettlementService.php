<?php

namespace App\Services\Wallet;

use App\Exceptions\PaymentGatewayException;
use App\Models\Payment\Account;
use App\Models\Payment\PaymentWebhook;
use App\Models\Payment\WalletDeposit;
use App\Models\User;
use App\Services\Admin\AuditEventService;
use App\Services\Payment\PaystackService;
use App\Services\Payment\WalletBalanceService;
use App\Services\Payment\WalletLedgerService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WalletDepositSettlementService
{
    public function __construct(
        private readonly BusinessSettingsService $settings,
        private readonly PaystackService $paystack,
        private readonly WalletLedgerService $ledger,
        private readonly WalletBalanceService $balances,
        private readonly AuditEventService $auditEvents,
    ) {}

    /**
     * Initialize a wallet deposit transaction with idempotency and domain allowlisting.
     *
     * @return array{deposit: WalletDeposit, replayed: bool}
     */
    public function initialize(
        User $user,
        int $amountMinor,
        string $method,
        string $callbackUrl,
        ?string $idempotencyKey = null,
    ): array {
        if (! $this->settings->value('wallet.deposits_enabled')) {
            throw ValidationException::withMessages([
                'deposit' => ['Wallet deposits are currently disabled.'],
            ]);
        }

        $this->assertAllowedCallbackUrl($callbackUrl);
        $this->assertDepositAmountWithinBounds($amountMinor);

        $requestHash = hash('sha256', "{$user->id}|{$amountMinor}|{$method}|{$callbackUrl}");

        if ($idempotencyKey) {
            $existing = WalletDeposit::where('user_id', $user->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing) {
                if ($existing->request_hash && $existing->request_hash !== $requestHash) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['This idempotency key has already been used for a deposit with different parameters.'],
                    ]);
                }

                return [
                    'deposit' => $existing,
                    'replayed' => true,
                ];
            }
        }

        $account = Account::firstOrCreate(
            ['user_id' => $user->id],
            ['currency' => 'NGN', 'balance' => 0]
        );

        $reference = 'WLT-DEP-'.strtoupper(Str::random(16));
        $expirationMinutes = (int) ($this->settings->value('wallet.deposit_expiration_minutes') ?? 1440);

        $paystackResponse = $this->paystack->initializeTransaction([
            'amount' => $amountMinor,
            'email' => $user->email,
            'reference' => $reference,
            'callback_url' => $callbackUrl,
            'metadata' => [
                'type' => 'wallet_deposit',
                'user_id' => $user->id,
                'account_id' => $account->id,
                'amount_minor' => $amountMinor,
            ],
        ]);

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
            'idempotency_key' => $idempotencyKey,
            'request_hash' => $requestHash,
            'expires_at' => now()->addMinutes($expirationMinutes),
            'metadata' => [
                'callback_url' => $callbackUrl,
            ],
        ]);

        $this->auditEvents->record(
            action: 'wallet_deposit.initialized',
            actor: $user,
            subject: $deposit,
            reason: 'Wallet deposit checkout initialized',
            after: [
                'reference' => $reference,
                'amount_minor' => $amountMinor,
                'currency' => $deposit->currency,
            ]
        );

        return [
            'deposit' => $deposit,
            'replayed' => false,
        ];
    }

    /**
     * Authoritative settlement executed solely by verified Paystack webhook.
     */
    public function settleFromWebhook(PaymentWebhook $webhook, array $data): WalletDeposit
    {
        $reference = (string) ($data['reference'] ?? $webhook->reference);

        return DB::transaction(function () use ($reference, $data, $webhook): WalletDeposit {
            /** @var WalletDeposit $deposit */
            $deposit = WalletDeposit::where('reference', $reference)
                ->lockForUpdate()
                ->firstOrFail();

            if ($deposit->isCompleted()) {
                return $deposit;
            }

            // Strict matching validation
            $amountMatches = (int) ($data['amount'] ?? -1) === (int) $deposit->amount_minor;
            if (! $amountMatches) {
                throw new PaymentGatewayException("Webhook payload amount does not match wallet deposit {$reference}.");
            }

            $currencyMatches = strtoupper((string) ($data['currency'] ?? '')) === strtoupper($deposit->currency);
            if (! $currencyMatches) {
                throw new PaymentGatewayException("Webhook payload currency does not match wallet deposit {$reference}.");
            }

            $statusMatches = strtolower((string) ($data['status'] ?? '')) === 'success';
            if (! $statusMatches) {
                throw new PaymentGatewayException("Webhook transaction status is not successful for wallet deposit {$reference}.");
            }

            // Verify user/account metadata when provided by the webhook payload
            $metaUserId = data_get($data, 'metadata.user_id');
            if ($metaUserId !== null && (int) $metaUserId !== (int) $deposit->user_id) {
                throw new PaymentGatewayException("Webhook payload user metadata does not match wallet deposit {$reference}.");
            }

            $metaAccountId = data_get($data, 'metadata.account_id');
            if ($metaAccountId !== null && (int) $metaAccountId !== (int) $deposit->account_id) {
                throw new PaymentGatewayException("Webhook payload account metadata does not match wallet deposit {$reference}.");
            }

            $providerRef = (string) ($data['id'] ?? $data['reference'] ?? $webhook->id);
            $before = $deposit->toArray();

            $deposit->update([
                'status' => 'completed',
                'paid_at' => now(),
                'provider_reference' => $providerRef,
                'failure_reason' => null,
            ]);

            // Authoritatively credit the wallet ledger
            $this->ledger->credit(
                $deposit->user,
                $deposit->amount_minor,
                'cash',
                'deposit',
                "wallet_deposit:{$deposit->id}",
                "Wallet deposit via {$deposit->method}",
                'wallet_deposit',
                $deposit->id,
                ['reference' => $deposit->reference, 'provider_reference' => $providerRef]
            );

            $this->auditEvents->record(
                action: 'wallet_deposit.completed',
                actor: $deposit->user,
                subject: $deposit,
                reason: 'Authoritative wallet deposit settlement from Paystack webhook',
                before: $before,
                after: $deposit->fresh()->toArray()
            );

            return $deposit->fresh();
        }, 3);
    }

    /**
     * Query and reconcile status without being the source of truth for crediting.
     *
     * @return array{status: string, deposit: WalletDeposit, message: string}
     */
    public function reconcileStatus(WalletDeposit $deposit): array
    {
        if ($deposit->isCompleted()) {
            return [
                'status' => 'completed',
                'deposit' => $deposit,
                'message' => 'Deposit verified and credited.',
            ];
        }

        if ($deposit->isExpired()) {
            if ($deposit->status !== 'expired') {
                $deposit->update([
                    'status' => 'expired',
                    'failure_reason' => 'Deposit expired before payment completion.',
                ]);
            }

            return [
                'status' => 'expired',
                'deposit' => $deposit->fresh(),
                'message' => 'Deposit has expired.',
            ];
        }

        try {
            $verified = $this->paystack->verifyTransaction($deposit->reference);
            $gatewayStatus = strtolower((string) ($verified['status'] ?? $verified['data']['status'] ?? ''));

            if (in_array($gatewayStatus, ['failed', 'abandoned'], true)) {
                $deposit->update([
                    'status' => 'failed',
                    'failure_reason' => "Transaction {$gatewayStatus} on payment gateway.",
                ]);

                return [
                    'status' => 'failed',
                    'deposit' => $deposit->fresh(),
                    'message' => "Payment {$gatewayStatus} on payment gateway.",
                ];
            }

            if ($gatewayStatus === 'success') {
                // Notice: Client verification cannot credit the wallet.
                // Awaiting authoritative Paystack webhook.
                return [
                    'status' => 'pending',
                    'deposit' => $deposit,
                    'message' => 'Payment confirmed by gateway; awaiting webhook settlement.',
                ];
            }
        } catch (\Throwable $e) {
            // Keep current status if gateway lookup fails
        }

        return [
            'status' => $deposit->status,
            'deposit' => $deposit,
            'message' => 'Payment is awaiting completion.',
        ];
    }

    /**
     * Prune and reconcile expired pending deposits.
     */
    public function pruneExpiredDeposits(): int
    {
        return WalletDeposit::where('status', 'pending')
            ->where('expires_at', '<', now())
            ->update([
                'status' => 'expired',
                'failure_reason' => 'Deposit expired before payment completion.',
            ]);
    }

    private function assertDepositAmountWithinBounds(int $amountMinor): void
    {
        $minMinor = (int) ($this->settings->value('wallet.minimum_deposit_minor') ?? 10000);
        $maxMinor = (int) ($this->settings->value('wallet.maximum_deposit_minor') ?? 50000000);

        if ($amountMinor < $minMinor) {
            throw ValidationException::withMessages([
                'amount' => ["The minimum deposit amount is {$this->balances->money($minMinor)}."],
            ]);
        }

        if ($amountMinor > $maxMinor) {
            throw ValidationException::withMessages([
                'amount' => ["The maximum deposit amount is {$this->balances->money($maxMinor)}."],
            ]);
        }
    }

    private function assertAllowedCallbackUrl(string $callbackUrl): void
    {
        $host = parse_url($callbackUrl, PHP_URL_HOST);
        if (! $host) {
            throw ValidationException::withMessages([
                'callback_url' => ['The callback URL is invalid.'],
            ]);
        }

        $allowedDomains = (array) ($this->settings->value('wallet.allowed_callback_domains') ?? []);

        // Also permit app.frontend_url host if set
        $frontendHost = parse_url((string) config('app.frontend_url'), PHP_URL_HOST);
        if ($frontendHost && ! in_array($frontendHost, $allowedDomains, true)) {
            $allowedDomains[] = $frontendHost;
        }

        $isAllowed = false;
        foreach ($allowedDomains as $domain) {
            $domain = strtolower(trim((string) $domain));
            if ($domain === '' || $domain === '*') {
                $isAllowed = true;
                break;
            }
            if (strtolower($host) === $domain || str_ends_with(strtolower($host), '.'.$domain)) {
                $isAllowed = true;
                break;
            }
        }

        if (! $isAllowed) {
            throw ValidationException::withMessages([
                'callback_url' => ["The callback URL host [{$host}] is not an authorized Limitra frontend origin."],
            ]);
        }
    }
}
