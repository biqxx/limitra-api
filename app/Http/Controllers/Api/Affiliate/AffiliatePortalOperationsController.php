<?php

namespace App\Http\Controllers\Api\Affiliate;

use App\Http\Controllers\Api\BaseController;
use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateLink;
use App\Models\Affiliate\AffiliatePayoutMethod;
use App\Models\Affiliate\AffiliatePromoCode;
use App\Models\Commerce\Promotion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AffiliatePortalOperationsController extends BaseController
{
    private function getAffiliate(Request $request): Affiliate
    {
        return Affiliate::where('user_id', $request->user('api')->id)->firstOrFail();
    }

    // ── Custom Links ────────────────────────────────────────────────────────────

    public function links(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);
        $links = $affiliate->links()->with('product:id,name,slug')->latest()->paginate($request->integer('per_page', 20));

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

    // ── Promo Codes ─────────────────────────────────────────────────────────────

    public function promoCodes(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);
        $codes = $affiliate->promoCodes()->with('promotion')->latest()->paginate($request->integer('per_page', 20));

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
            'code' => ['required', 'string', 'min:4', 'max:30', 'alpha_dash', 'unique:affiliate_promo_codes,code', 'unique:promotions,code'],
            'discount_percent' => ['nullable', 'numeric', 'min:1', 'max:20'],
        ]);

        $code = strtoupper($validated['code']);
        $discount = (float) ($validated['discount_percent'] ?? 5.00);

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
            'is_default' => $m->is_default,
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
        if ($isDefault || ! $affiliate->payoutMethods()->exists()) {
            $isDefault = true;
            $affiliate->payoutMethods()->update(['is_default' => false]);
        }

        $method = $affiliate->payoutMethods()->create([
            'bank_name' => $validated['bank_name'],
            'bank_code' => $validated['bank_code'] ?? null,
            'account_number' => $validated['account_number'],
            'account_name' => $validated['account_name'],
            'is_default' => $isDefault,
        ]);

        return $this->success([
            'id' => $method->id,
            'bank_name' => $method->bank_name,
            'bank_code' => $method->bank_code,
            'account_number_masked' => $method->masked_account_number,
            'account_name' => $method->account_name,
            'is_default' => $method->is_default,
        ], 'Payout method added successfully.', 201);
    }

    public function setDefaultPayoutMethod(Request $request, AffiliatePayoutMethod $method): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);
        if ($method->affiliate_id !== $affiliate->id) {
            return $this->error('Unauthorized.', 403);
        }

        $affiliate->payoutMethods()->update(['is_default' => false]);
        $method->update(['is_default' => true]);

        return $this->success(null, 'Default payout method updated.');
    }

    public function destroyPayoutMethod(Request $request, AffiliatePayoutMethod $method): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);
        if ($method->affiliate_id !== $affiliate->id) {
            return $this->error('Unauthorized.', 403);
        }

        $method->delete();

        return $this->success(null, 'Payout method removed.');
    }

    // ── Withdrawals ─────────────────────────────────────────────────────────────

    public function withdrawals(Request $request): JsonResponse
    {
        $affiliate = $this->getAffiliate($request);
        $withdrawals = $affiliate->withdrawals()->with('payoutMethod')->latest()->paginate($request->integer('per_page', 20));

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

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:1000'],
            'payout_method_id' => ['nullable', 'exists:affiliate_payout_methods,id'],
        ]);

        $availableBalance = (float) $affiliate->pending_balance;
        $requestedAmount = (float) $validated['amount'];

        if ($requestedAmount > $availableBalance) {
            throw ValidationException::withMessages([
                'amount' => ["Requested withdrawal ({$requestedAmount}) exceeds available balance ({$availableBalance})."],
            ]);
        }

        $payoutMethodId = $validated['payout_method_id'] ?? $affiliate->payoutMethods()->where('is_default', true)->value('id');

        $withdrawal = $affiliate->withdrawals()->create([
            'payout_method_id' => $payoutMethodId,
            'amount' => $requestedAmount,
            'currency' => 'NGN',
            'status' => 'pending',
            'reference' => 'WTH-'.strtoupper(Str::random(12)),
        ]);

        return $this->success($withdrawal, 'Withdrawal request submitted successfully.', 201);
    }
}
