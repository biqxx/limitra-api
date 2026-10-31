<?php

namespace App\Services\Affiliate;

use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateClick;
use App\Models\Affiliate\AffiliateCommission;
use App\Models\Affiliate\AffiliateLink;
use App\Models\Affiliate\AffiliatePromoCode;
use App\Models\Affiliate\AffiliateSale;
use App\Models\Order\Order;
use App\Models\User;
use App\Notifications\Affiliate\AffiliateCommissionEarnedNotification;
use App\Services\Settings\BusinessSettingsService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Cookie;

class AffiliateAttributionService
{
    public const COOKIE_NAME = 'limitra_affiliate';

    public function __construct(
        private readonly AffiliateLedgerService $ledgerService,
        private readonly AffiliateTierService $tierService,
        private readonly BusinessSettingsService $settings
    ) {}

    /**
     * Resolve public tracking link click, filter bots and self-referral, and produce safe redirect.
     *
     * @return array{destination: string, cookie: ?Cookie}
     */
    public function resolveTrackingClick(string $slug, Request $request): array
    {
        $link = AffiliateLink::with(['affiliate', 'product:id,name,slug'])
            ->where('slug', $slug)
            ->where('is_active', true)
            ->firstOrFail();

        $destinationUrl = $this->determineDestinationUrl($link);
        $safeDestination = $this->sanitizeDestinationUrl($destinationUrl);

        $isBot = $this->isBot($request->userAgent());
        $isSelfReferral = false;

        $user = $request->user('api');
        if ($user && $user->id === $link->affiliate->user_id) {
            $isSelfReferral = true;
        }

        $ipHash = hash('sha256', (string) $request->ip().config('app.key').now()->toDateString());
        $userAgent = substr((string) $request->userAgent(), 0, 250);

        $click = AffiliateClick::create([
            'affiliate_id' => $link->affiliate_id,
            'affiliate_link_id' => $link->id,
            'destination_url' => $safeDestination,
            'ip_hash' => $ipHash,
            'user_agent' => $userAgent,
            'is_bot' => $isBot,
            'is_self_referral' => $isSelfReferral,
            'converted' => false,
            'created_at' => now(),
        ]);

        $cookie = null;
        if (! $isBot && ! $isSelfReferral) {
            $link->increment('clicks_count');

            $windowDays = $this->getAttributionWindowDays();
            $payload = [
                'affiliate_id' => $link->affiliate_id,
                'link_id' => $link->id,
                'click_id' => $click->id,
                'code' => $link->affiliate->code,
                'timestamp' => now()->timestamp,
            ];

            $encrypted = Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
            $cookie = cookie(
                self::COOKIE_NAME,
                $encrypted,
                $windowDays * 24 * 60,
                '/',
                null,
                $request->isSecure(),
                true,
                false,
                'Lax'
            );
        }

        return [
            'destination' => $safeDestination,
            'cookie' => $cookie,
        ];
    }

    /**
     * Decode attribution cookie token.
     *
     * @return array<string, mixed>|null
     */
    public function decodeAttributionCookie(?string $rawCookie): ?array
    {
        if (! $rawCookie) {
            return null;
        }

        try {
            $decrypted = Crypt::decryptString($rawCookie);
            $payload = json_decode($decrypted, true, 512, JSON_THROW_ON_ERROR);

            if (! is_array($payload) || ! isset($payload['affiliate_id'], $payload['timestamp'])) {
                return null;
            }

            $windowDays = $this->getAttributionWindowDays();
            $expiryCutoff = Carbon::createFromTimestamp($payload['timestamp'])->addDays($windowDays);

            if ($expiryCutoff->isPast()) {
                return null;
            }

            return $payload;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Deterministic precedence resolution:
     * 1. Promo Code applied to order
     * 2. Tracked Link / Cookie Token / Explicit direct ref code
     * 3. Indirect Referral (user.referred_by)
     *
     * @return array{affiliate: Affiliate, sale_type: string, link_id: ?int, promo_code_id: ?int, click_id: ?int}|null
     */
    public function resolveAffiliateForOrder(Order $order, ?string $explicitCode = null, ?array $cookiePayload = null): ?array
    {
        $order->loadMissing(['user', 'checkoutQuote']);

        // Priority 1: Affiliate Promo Code applied to Checkout Quote / Order
        $promotionId = $order->checkoutQuote?->promotion_id;
        if ($promotionId) {
            $promoCode = AffiliatePromoCode::with('affiliate')
                ->where('promotion_id', $promotionId)
                ->where('is_active', true)
                ->first();

            if ($promoCode && $promoCode->affiliate->isActive()) {
                if ($promoCode->affiliate->user_id !== $order->user_id) {
                    return [
                        'affiliate' => $promoCode->affiliate,
                        'sale_type' => 'direct',
                        'link_id' => null,
                        'promo_code_id' => $promoCode->id,
                        'click_id' => null,
                    ];
                }
            }
        }

        // Priority 2: Tracked Link Cookie Token or Explicit Ref Code
        if ($cookiePayload && isset($cookiePayload['affiliate_id'])) {
            $affiliate = Affiliate::where('id', $cookiePayload['affiliate_id'])
                ->where('status', 'active')
                ->first();

            if ($affiliate && $affiliate->user_id !== $order->user_id) {
                return [
                    'affiliate' => $affiliate,
                    'sale_type' => 'direct',
                    'link_id' => $cookiePayload['link_id'] ?? null,
                    'promo_code_id' => null,
                    'click_id' => $cookiePayload['click_id'] ?? null,
                ];
            }
        }

        if ($explicitCode) {
            $affiliate = Affiliate::where('code', $explicitCode)
                ->where('status', 'active')
                ->first();

            if ($affiliate && $affiliate->user_id !== $order->user_id) {
                return [
                    'affiliate' => $affiliate,
                    'sale_type' => 'direct',
                    'link_id' => null,
                    'promo_code_id' => null,
                    'click_id' => null,
                ];
            }
        }

        // Priority 3: Indirect Referral from customer's recruiting affiliate
        if ($order->user && $order->user->referred_by) {
            $affiliate = Affiliate::where('id', $order->user->referred_by)
                ->where('status', 'active')
                ->first();

            if ($affiliate && $affiliate->user_id !== $order->user_id) {
                return [
                    'affiliate' => $affiliate,
                    'sale_type' => 'indirect',
                    'link_id' => null,
                    'promo_code_id' => null,
                    'click_id' => null,
                ];
            }
        }

        return null;
    }

    /**
     * Record sales, commissions, ledger entries and link conversion idempotently.
     */
    public function attributeConversion(Order $order, ?string $explicitCode = null, ?string $cookieToken = null): void
    {
        if (AffiliateSale::where('order_id', $order->id)->exists()) {
            return;
        }

        $order->loadMissing('items');
        if ($order->items->isEmpty()) {
            return;
        }

        $cookiePayload = $this->decodeAttributionCookie($cookieToken);
        $resolved = $this->resolveAffiliateForOrder($order, $explicitCode, $cookiePayload);

        if (! $resolved) {
            return;
        }

        $affiliate = $resolved['affiliate'];
        $saleType = $resolved['sale_type'];
        $linkId = $resolved['link_id'];
        $promoCodeId = $resolved['promo_code_id'];
        $clickId = $resolved['click_id'];

        DB::transaction(function () use ($order, $affiliate, $saleType, $linkId, $promoCodeId, $clickId): void {
            if (AffiliateSale::where('order_id', $order->id)->lockForUpdate()->exists()) {
                return;
            }

            /** @var Affiliate $lockedAffiliate */
            $lockedAffiliate = Affiliate::whereKey($affiliate->id)->lockForUpdate()->firstOrFail();
            $totalCommission = 0.0;

            foreach ($order->items as $item) {
                $saleAmount = $item->price_at_purchase * $item->quantity;

                $sale = AffiliateSale::create([
                    'affiliate_id' => $lockedAffiliate->id,
                    'order_id' => $order->id,
                    'order_item_id' => $item->id,
                    'product_id' => $item->product_id,
                    'buyer_user_id' => $order->user_id,
                    'sale_type' => $saleType,
                    'sale_amount' => $saleAmount,
                    'affiliate_link_id' => $linkId,
                    'affiliate_promo_code_id' => $promoCodeId,
                ]);

                $commissionAmount = round($saleAmount * $lockedAffiliate->commission_rate / 100, 2);

                $commission = AffiliateCommission::create([
                    'affiliate_id' => $lockedAffiliate->id,
                    'affiliate_sale_id' => $sale->id,
                    'commission_percent' => $lockedAffiliate->commission_rate,
                    'commission_amount' => $commissionAmount,
                    'payment_status' => 'pending',
                ]);

                $totalCommission += $commissionAmount;
            }

            if ($totalCommission > 0) {
                $this->ledgerService->recordEarning(
                    affiliate: $lockedAffiliate,
                    amount: $totalCommission,
                    referenceType: Order::class,
                    referenceId: $order->id,
                    description: "Affiliate commission for order {$order->number}",
                    idempotencyKey: "order-commission-{$order->id}"
                );
            }

            // Mark click as converted if tracking click is known
            if ($clickId) {
                AffiliateClick::where('id', $clickId)->update([
                    'converted' => true,
                    'converted_at' => now(),
                ]);
            }

            // Increment link conversions if link used
            if ($linkId) {
                AffiliateLink::where('id', $linkId)->increment('conversions_count');
            }

            // Evaluate rolling tier progression
            $this->tierService->evaluateTier($lockedAffiliate);

            // Notify affiliate user
            if ($lockedAffiliate->user && $totalCommission > 0) {
                $lockedAffiliate->user->notify(new AffiliateCommissionEarnedNotification(
                    orderNumber: $order->number,
                    amount: $totalCommission,
                    currency: 'NGN'
                ));
            }
        });
    }

    /**
     * Reverse commissions for a cancelled or fully refunded order using compensating ledger entries.
     */
    public function reverseCommissionsForOrder(Order $order, string $reason): void
    {
        $sales = AffiliateSale::with('commission')->where('order_id', $order->id)->get();
        if ($sales->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($order, $sales, $reason): void {
            $affiliateGroups = $sales->groupBy('affiliate_id');

            foreach ($affiliateGroups as $affiliateId => $groupedSales) {
                /** @var Affiliate $lockedAffiliate */
                $lockedAffiliate = Affiliate::whereKey($affiliateId)->lockForUpdate()->firstOrFail();
                $totalToReverse = 0.0;

                foreach ($groupedSales as $sale) {
                    $commission = $sale->commission;
                    if ($commission && in_array($commission->payment_status, ['pending', 'approved'], true)) {
                        $commission->payment_status = 'rejected';
                        $commission->save();
                        $totalToReverse += (float) $commission->commission_amount;
                    }
                }

                if ($totalToReverse > 0) {
                    $this->ledgerService->recordReversal(
                        affiliate: $lockedAffiliate,
                        amount: $totalToReverse,
                        referenceType: Order::class,
                        referenceId: $order->id,
                        reason: "Reversal for order {$order->number}: {$reason}"
                    );
                }
            }
        });
    }

    // ── Internal Helpers ──────────────────────────────────────────────────────

    private function determineDestinationUrl(AffiliateLink $link): string
    {
        if (! empty($link->landing_page)) {
            return $link->landing_page;
        }

        if ($link->product_id && $link->product) {
            return '/products/'.($link->product->slug ?? $link->product_id);
        }

        return '/';
    }

    private function sanitizeDestinationUrl(string $url): string
    {
        // Relative paths are always safe internal redirects
        if (str_starts_with($url, '/')) {
            return $url;
        }

        $parts = parse_url($url);
        if (! isset($parts['host'])) {
            return '/';
        }

        $host = strtolower($parts['host']);
        $allowed = $this->getAllowedDomains();

        if (in_array($host, $allowed, true)) {
            return $url;
        }

        // Untrusted domain: fallback to home to prevent open redirect attacks
        return '/';
    }

    /** @return array<int, string> */
    private function getAllowedDomains(): array
    {
        try {
            $domains = (array) $this->settings->value('affiliates.allowed_redirect_domains');
        } catch (\Throwable) {
            $domains = ['localhost', '127.0.0.1', 'limitra.test', 'limitra.com'];
        }

        $appHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        if ($appHost) {
            $domains[] = strtolower($appHost);
        }

        return array_values(array_unique(array_filter(array_map('strtolower', $domains))));
    }

    private function getAttributionWindowDays(): int
    {
        try {
            return (int) $this->settings->value('affiliates.attribution_window_days');
        } catch (\Throwable) {
            return 30;
        }
    }

    private function isBot(?string $userAgent): bool
    {
        if (! $userAgent) {
            return true;
        }

        return (bool) preg_match('/bot|crawl|spider|slurp|facebookexternalhit|curl|wget|python|httpclient|postman/i', $userAgent);
    }
}
