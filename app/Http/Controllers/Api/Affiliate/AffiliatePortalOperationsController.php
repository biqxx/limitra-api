<?php

namespace App\Http\Controllers\Api\Affiliate;

use App\Http\Controllers\Api\BaseController;
use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateLink;
use App\Models\Affiliate\AffiliatePayoutMethod;
use App\Models\Affiliate\AffiliatePromoCode;
use App\Models\Affiliate\AffiliateResource;
use App\Models\Commerce\Promotion;
use App\Models\Product\Product;
use App\Services\Admin\AuditEventService;
use App\Services\Affiliate\AffiliateLedgerService;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AffiliatePortalOperationsController extends BaseController
{
    public function __construct(
        private readonly AffiliateLedgerService $ledgerService,
        private readonly BusinessSettingsService $businessSettings,
        private readonly AuditEventService $audits
    ) {}

    private function getAffiliate(Request $request): Affiliate
    {
        return Affiliate::where('user_id', $request->user('api')->id)->firstOrFail();
    }

    // ── Custom Links ────────────────────────────────────────────────────────────

    public function links(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);
        $links = $affiliate->links()->with('product:id,name,slug')->latest('id')->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => $links->items(),
            'pagination' => [
                'current_page' => $links->currentPage(),
                'per_page' => $links->perPage(),
                'total' => $links->total(),
                'last_page' => $links->lastPage(),
                'from' => $links->firstItem(),
                'to' => $links->lastItem(),
            ],
        ]);
    }

    public function storeLink(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);

        $validated = $request->validate([
            'product_id' => ['nullable', 'exists:products,id'],
            'campaign' => ['nullable', 'string', 'max:100'],
            'landing_page' => ['nullable', 'string', 'max:255'],
        ]);

        $slug = 'ref-'.strtolower($affiliate->code).'-'.strtolower(Str::random(6));

        $link = $affiliate->links()->create([
            'product_id' => $validated['product_id'] ?? null,
            'campaign' => $validated['campaign'] ?? null,
            'landing_page' => $validated['landing_page'] ?? null,
            'slug' => $slug,
            'is_active' => true,
        ]);

        $baseUrl = config('app.url', 'https://limitra.test');
        $trackedUrl = "{$baseUrl}/l/{$slug}";

        return $this->success([
            'link' => $link->load('product:id,name,slug'),
            'tracked_url' => $trackedUrl,
        ], 'Affiliate custom link generated.', 201);
    }

    public function destroyLink(Request $request, AffiliateLink $link): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);
        if ($link->affiliate_id !== $affiliate->id) {
            return $this->error('Unauthorized.', 403);
        }

        $link->update(['is_active' => false]);

        return $this->success(null, 'Affiliate link deactivated.');
    }

    public function linkAnalytics(Request $request, AffiliateLink $link): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);
        if ($link->affiliate_id !== $affiliate->id) {
            return $this->error('Unauthorized.', 403);
        }

        $clicksCount = (int) $link->clicks()->count();
        $conversionsCount = (int) $link->clicks()->where('converted', true)->count();
        $conversionRate = $clicksCount > 0 ? round(($conversionsCount / $clicksCount) * 100, 2) : 0.0;

        $recentClicks = $link->clicks()->latest('created_at')->limit(20)->get(['id', 'destination_url', 'is_bot', 'converted', 'created_at']);

        return $this->success([
            'link_id' => $link->id,
            'slug' => $link->slug,
            'clicks_count' => $clicksCount,
            'conversions_count' => $conversionsCount,
            'conversion_rate_percentage' => $conversionRate,
            'recent_clicks' => $recentClicks,
        ], 'Link analytics retrieved.');
    }

    // ── Promo Codes ─────────────────────────────────────────────────────────────

    public function promoCodes(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);
        $codes = $affiliate->promoCodes()->with('promotion')->latest('id')->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => $codes->items(),
            'pagination' => [
                'current_page' => $codes->currentPage(),
                'per_page' => $codes->perPage(),
                'total' => $codes->total(),
                'last_page' => $codes->lastPage(),
                'from' => $codes->firstItem(),
                'to' => $codes->lastItem(),
            ],
        ]);
    }

    public function storePromoCode(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);

        $validated = $request->validate([
            'code' => ['required', 'string', 'min:4', 'max:30', 'alpha_dash'],
            'discount_percent' => ['nullable', 'numeric', 'min:1', 'max:20'],
        ]);

        $code = strtoupper(trim($validated['code']));
        $discount = (float) ($validated['discount_percent'] ?? 5.00);

        // Case-insensitively unique check against both tables
        $promoExists = AffiliatePromoCode::whereRaw('UPPER(code) = ?', [$code])->exists()
            || Promotion::whereRaw('UPPER(code) = ?', [$code])->exists();

        if ($promoExists) {
            throw ValidationException::withMessages([
                'code' => ['The promo code has already been taken.'],
            ]);
        }

        return DB::transaction(function () use ($affiliate, $code, $discount): JsonResponse {
            $promotion = Promotion::create([
                'code' => $code,
                'name' => "Affiliate Promo: {$code}",
                'type' => 'percentage',
                'value' => $discount,
                'active' => true,
            ]);

            $affiliatePromo = $affiliate->promoCodes()->create([
                'promotion_id' => $promotion->id,
                'code' => $code,
                'discount_percent' => $discount,
                'is_active' => true,
            ]);

            return $this->success($affiliatePromo->load('promotion'), 'Affiliate promo code created.', 201);
        });
    }

    public function destroyPromoCode(Request $request, AffiliatePromoCode $promoCode): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);
        if ($promoCode->affiliate_id !== $affiliate->id) {
            return $this->error('Unauthorized.', 403);
        }

        $promoCode->update(['is_active' => false]);
        $promoCode->promotion?->update(['active' => false]);

        return $this->success(null, 'Affiliate promo code deactivated.');
    }

    // ── Payout Methods ──────────────────────────────────────────────────────────

    public function payoutMethods(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);
        $methods = $affiliate->payoutMethods()->latest('is_default')->get()->map(fn ($m) => [
            'id' => $m->id,
            'bank_name' => $m->bank_name,
            'bank_code' => $m->bank_code,
            'account_number_masked' => $m->masked_account_number,
            'account_name' => $m->account_name,
            'is_default' => (bool) $m->is_default,
            'is_verified' => (bool) $m->is_verified,
            'created_at' => $m->created_at->toIso8601String(),
        ]);

        return $this->success($methods, 'Payout methods retrieved.');
    }

    public function storePayoutMethod(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);

        $validated = $request->validate([
            'bank_name' => ['required', 'string', 'max:100'],
            'bank_code' => ['nullable', 'string', 'max:20'],
            'account_number' => ['required', 'string', 'min:8', 'max:50'],
            'account_name' => ['required', 'string', 'max:150'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $isDefault = (bool) ($validated['is_default'] ?? false);

        return DB::transaction(function () use ($affiliate, $validated, $isDefault): JsonResponse {
            if ($isDefault || ! $affiliate->payoutMethods()->lockForUpdate()->exists()) {
                $isDefault = true;
                $affiliate->payoutMethods()->lockForUpdate()->update(['is_default' => false]);
            }

            $method = $affiliate->payoutMethods()->create([
                'bank_name' => $validated['bank_name'],
                'bank_code' => $validated['bank_code'] ?? null,
                'account_number' => $validated['account_number'],
                'account_name' => $validated['account_name'],
                'is_default' => $isDefault,
                'is_verified' => false,
            ]);

            return $this->success([
                'id' => $method->id,
                'bank_name' => $method->bank_name,
                'bank_code' => $method->bank_code,
                'account_number_masked' => $method->masked_account_number,
                'account_name' => $method->account_name,
                'is_default' => (bool) $method->is_default,
                'is_verified' => (bool) $method->is_verified,
            ], 'Payout method added successfully.', 201);
        });
    }

    public function verifyPayoutMethod(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'bank_code' => ['required', 'string', 'max:20'],
            'account_number' => ['required', 'string', 'min:8', 'max:20'],
        ]);

        $secretKey = config('services.paystack.secret_key');
        $resolvedName = null;
        $isVerified = false;

        if ($secretKey) {
            try {
                $response = Http::withToken($secretKey)
                    ->timeout(10)
                    ->get('https://api.paystack.co/bank/resolve', [
                        'account_number' => $validated['account_number'],
                        'bank_code' => $validated['bank_code'],
                    ]);

                if ($response->successful() && $response->json('status') === true) {
                    $resolvedName = $response->json('data.account_name');
                    $isVerified = true;
                }
            } catch (\Throwable) {
                // Fallback gracefully
            }
        }

        return $this->success([
            'bank_code' => $validated['bank_code'],
            'account_number_masked' => substr_replace($validated['account_number'], '******', 0, -4),
            'account_name' => $resolvedName,
            'is_verified' => $isVerified,
        ], $isVerified ? 'Bank account resolved successfully.' : 'Bank account could not be verified automatically.');
    }

    public function setDefaultPayoutMethod(Request $request, AffiliatePayoutMethod $method): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);
        if ($method->affiliate_id !== $affiliate->id) {
            return $this->error('Unauthorized.', 403);
        }

        return DB::transaction(function () use ($affiliate, $method): JsonResponse {
            $affiliate->payoutMethods()->lockForUpdate()->update(['is_default' => false]);
            $method->whereKey($method->id)->lockForUpdate()->update(['is_default' => true]);

            return $this->success(null, 'Default payout method updated.');
        });
    }

    public function destroyPayoutMethod(Request $request, AffiliatePayoutMethod $method): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);
        if ($method->affiliate_id !== $affiliate->id) {
            return $this->error('Unauthorized.', 403);
        }

        // Prevent deleting a payout method referenced by a pending, approved, processing, or paid withdrawal
        if ($method->withdrawals()->whereIn('status', ['pending', 'approved', 'processing', 'paid'])->exists()) {
            return $this->error('Cannot delete a payout method that has associated pending or processed withdrawals.', 422);
        }

        $method->delete();

        return $this->success(null, 'Payout method removed.');
    }

    // ── Withdrawals ─────────────────────────────────────────────────────────────

    public function withdrawals(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);
        $withdrawals = $affiliate->withdrawals()->with('payoutMethod')->latest('id')->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => $withdrawals->items(),
            'pagination' => [
                'current_page' => $withdrawals->currentPage(),
                'per_page' => $withdrawals->perPage(),
                'total' => $withdrawals->total(),
                'last_page' => $withdrawals->lastPage(),
                'from' => $withdrawals->firstItem(),
                'to' => $withdrawals->lastItem(),
            ],
        ]);
    }

    public function storeWithdrawal(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);

        $minWithdrawal = 5000.00;
        try {
            $minWithdrawal = (float) $this->businessSettings->value('affiliates.min_withdrawal_amount');
        } catch (\Throwable) {
            $minWithdrawal = 1000.00;
        }

        $validated = $request->validate([
            'amount' => ['required', 'numeric', "min:{$minWithdrawal}"],
            'payout_method_id' => ['nullable', 'integer', 'exists:affiliate_payout_methods,id'],
        ]);

        $requestedAmount = round((float) $validated['amount'], 2);
        $payoutMethodId = $validated['payout_method_id'] ?? null;

        if ($payoutMethodId) {
            $belongs = $affiliate->payoutMethods()->whereKey($payoutMethodId)->exists();
            if (! $belongs) {
                return $this->error('The selected payout method does not belong to your account.', 422);
            }
        } else {
            $payoutMethodId = $affiliate->payoutMethods()->where('is_default', true)->value('id');
            if (! $payoutMethodId) {
                return $this->error('Please select or add a default payout method before requesting a withdrawal.', 422);
            }
        }

        // Idempotency replay check
        $idempotencyKey = $request->header('Idempotency-Key');
        $requestHash = hash('sha256', json_encode([
            'amount' => $requestedAmount,
            'payout_method_id' => $payoutMethodId,
        ], JSON_THROW_ON_ERROR));

        if ($idempotencyKey) {
            $existing = $affiliate->withdrawals()->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->request_hash && $existing->request_hash !== $requestHash) {
                    return $this->error('Idempotency key reused with different request payload.', 409);
                }

                return $this->success($existing->load('payoutMethod'), 'Withdrawal request replayed.');
            }
        }

        return DB::transaction(function () use ($affiliate, $requestedAmount, $payoutMethodId, $idempotencyKey, $requestHash, $request): JsonResponse {
            /** @var Affiliate $lockedAffiliate */
            $lockedAffiliate = Affiliate::whereKey($affiliate->id)->lockForUpdate()->firstOrFail();

            $availableBalance = $this->ledgerService->calculateAuthoritativeAvailableBalance($lockedAffiliate);
            if ($requestedAmount > $availableBalance) {
                throw ValidationException::withMessages([
                    'amount' => ["Requested withdrawal ({$requestedAmount}) exceeds available balance ({$availableBalance})."],
                ]);
            }

            $withdrawal = $lockedAffiliate->withdrawals()->create([
                'payout_method_id' => $payoutMethodId,
                'amount' => $requestedAmount,
                'currency' => 'NGN',
                'status' => 'pending',
                'reference' => 'WTH-'.strtoupper(Str::random(12)),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
            ]);

            // Place balance hold immediately to prevent concurrent over-withdrawal
            $this->ledgerService->recordHold(
                affiliate: $lockedAffiliate,
                amount: $requestedAmount,
                withdrawal: $withdrawal,
                idempotencyKey: $idempotencyKey
            );

            $this->audits->record(
                action: 'affiliate_withdrawal.requested',
                actor: $request->user('api'),
                subject: $withdrawal,
                reason: 'Affiliate requested withdrawal payout',
                after: $withdrawal->toArray(),
            );

            return $this->success($withdrawal->load('payoutMethod'), 'Withdrawal request submitted successfully.', 201);
        });
    }

    // ── Ledger / Transactions ───────────────────────────────────────────────────

    public function ledger(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);

        $entries = $affiliate->ledgerEntries()
            ->latest('created_at')
            ->paginate($request->integer('per_page', 20));

        return $this->success([
            'available_balance' => $affiliate->getAuthoritativeAvailableBalance(),
            'items' => $entries->items(),
            'pagination' => [
                'current_page' => $entries->currentPage(),
                'per_page' => $entries->perPage(),
                'total' => $entries->total(),
                'last_page' => $entries->lastPage(),
                'from' => $entries->firstItem(),
                'to' => $entries->lastItem(),
            ],
        ], 'Affiliate ledger retrieved.');
    }

    // ── Promotable Products ─────────────────────────────────────────────────────

    public function promotableProducts(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);
        $rate = (float) $affiliate->commission_rate;

        $query = Product::query()
            ->with('category:id,name,slug')
            ->where('status', 'active');

        if ($request->filled('search')) {
            $search = (string) $request->input('search');
            $query->where('name', 'like', "%{$search}%");
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        $paginator = $query->latest('id')->paginate($request->integer('per_page', 20));

        $items = collect($paginator->items())->map(function (Product $product) use ($rate): array {
            $price = (float) $product->price;
            $estimatedCommission = round($price * ($rate / 100), 2);

            return [
                'id' => $product->id,
                'name' => $product->name,
                'slug' => $product->slug,
                'category' => $product->category?->name,
                'price' => $price,
                'commission_rate' => $rate,
                'estimated_commission' => $estimatedCommission,
            ];
        });

        return $this->success([
            'commission_rate' => $rate,
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
        ], 'Promotable products retrieved.');
    }

    // ── Resources ───────────────────────────────────────────────────────────────

    public function resources(Request $request): JsonResponse
    {
        $resources = AffiliateResource::query()
            ->where('is_published', true)
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return $this->success([
            'items' => $resources->items(),
            'pagination' => [
                'current_page' => $resources->currentPage(),
                'per_page' => $resources->perPage(),
                'total' => $resources->total(),
                'last_page' => $resources->lastPage(),
                'from' => $resources->firstItem(),
                'to' => $resources->lastItem(),
            ],
        ], 'Affiliate marketing resources retrieved.');
    }

    public function downloadResource(Request $request, AffiliateResource $resource): BinaryFileResponse|JsonResponse
    {
        if (! $resource->is_published) {
            return $this->error('Resource is not available.', 404);
        }

        $resource->increment('downloads_count');

        $disk = Storage::disk('private');
        if (! $disk->exists($resource->file_path)) {
            // Also check default local disk
            if (! Storage::disk('local')->exists($resource->file_path)) {
                return $this->error('File not found.', 404);
            }
            $path = Storage::disk('local')->path($resource->file_path);
        } else {
            $path = $disk->path($resource->file_path);
        }

        return response()->download($path, $resource->title);
    }

    // ── Settings ────────────────────────────────────────────────────────────────

    public function settings(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);

        return $this->success([
            'id' => $affiliate->id,
            'code' => $affiliate->code,
            'slug' => $affiliate->slug,
            'current_tier' => $affiliate->current_tier,
            'commission_rate' => (float) $affiliate->commission_rate,
            'payout_details' => $affiliate->payout_details,
            'default_payout_method' => $affiliate->payoutMethods()->where('is_default', true)->first(),
        ], 'Affiliate settings retrieved.');
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);

        $validated = $request->validate([
            'payout_details' => ['nullable', 'array'],
        ]);

        $affiliate->update([
            'payout_details' => $validated['payout_details'] ?? $affiliate->payout_details,
        ]);

        return $this->success($affiliate->fresh(), 'Affiliate settings updated.');
    }
}
