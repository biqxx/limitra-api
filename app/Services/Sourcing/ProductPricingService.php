<?php

namespace App\Services\Sourcing;

use App\Models\Product\ProductPriceQuote;
use App\Models\Product\ProductSource;
use App\Models\Settings\BusinessSetting;
use App\Models\User;
use App\Services\Admin\AuditEventService;
use App\Services\Currency\CurrencyExchangeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;

class ProductPricingService
{
    private const REQUIRED_RULES = [
        'pricing.sales_tax_basis_points',
        'pricing.air_cargo_usd_cents_per_lb',
        'pricing.customs_basis_points',
        'pricing.processing_fee_ngn_minor',
        'pricing.profit_markup_basis_points',
        'pricing.fx_buffer_ngn_minor',
        'pricing.rounding_unit_ngn_minor',
        'pricing.max_weight_ounces',
        'pricing.max_us_delivery_days',
        'pricing.reprice_threshold_basis_points',
        'pricing.include_local_shipping',
        'pricing.local_shipping_ngn_minor',
        'pricing.restricted_keywords',
    ];

    public function __construct(
        private readonly CurrencyExchangeService $exchangeRates,
        private readonly AuditEventService $auditEvents,
    ) {}

    /**
     * @param  array<string, mixed>  $supplierProduct
     * @param  array<string, mixed>  $catalogContent
     * @return array<string, mixed>
     */
    public function prepareQuote(array $supplierProduct, array $catalogContent = []): array
    {
        [$rules, $rulesVersion] = $this->rules();
        $supplierPrice = $this->positiveFloat($supplierProduct['price'] ?? null);
        $supplierCurrency = strtoupper(trim((string) ($supplierProduct['currency'] ?? '')));
        $weightLb = $this->positiveFloat($supplierProduct['weight_lb'] ?? null);
        $deliveryDaysMin = $this->positiveInteger($supplierProduct['us_delivery_days_min'] ?? null);
        $deliveryDaysMax = $this->positiveInteger($supplierProduct['us_delivery_days_max'] ?? null);
        $missingInputs = array_values(array_filter([
            $supplierPrice === null ? 'supplier_price' : null,
            preg_match('/^[A-Z]{3}$/', $supplierCurrency) === 1 ? null : 'supplier_currency',
            $deliveryDaysMax === null ? 'delivery_days_max' : null,
        ]));
        $blockedReasons = [];
        $maxWeightLb = ((int) $rules['pricing.max_weight_ounces']) / 16;

        if ($weightLb !== null && $weightLb > $maxWeightLb) {
            $blockedReasons[] = 'weight_above_limit';
        }

        if ($deliveryDaysMax !== null && $deliveryDaysMax > (int) $rules['pricing.max_us_delivery_days']) {
            $blockedReasons[] = 'delivery_above_limit';
        }

        $restrictedKeyword = $this->restrictedKeyword(
            implode(' ', array_filter([
                $catalogContent['name'] ?? null,
                $catalogContent['description'] ?? null,
                $supplierProduct['title'] ?? null,
                $supplierProduct['description'] ?? null,
            ])),
            $rules['pricing.restricted_keywords'],
        );

        if ($restrictedKeyword !== null) {
            $blockedReasons[] = 'restricted_keyword:'.$restrictedKeyword;
        }

        $base = [
            'supplier_price' => $supplierPrice,
            'supplier_currency' => $supplierCurrency !== '' ? $supplierCurrency : null,
            'weight_lb' => $weightLb,
            'delivery_days_min' => $deliveryDaysMin,
            'delivery_days_max' => $deliveryDaysMax,
            'rules_snapshot' => $rules,
            'rules_version' => $rulesVersion,
            'missing_inputs' => $missingInputs,
            'blocked_reasons' => $blockedReasons,
            'quoted_at' => now(),
        ];

        if ($missingInputs !== []) {
            return [
                ...$base,
                'status' => 'pending_inputs',
                'pricing_status' => 'pending_inputs',
                'proposed_price_ngn' => null,
                'cost_breakdown' => null,
            ];
        }

        $fxBuffer = $this->minorToNaira((int) $rules['pricing.fx_buffer_ngn_minor']);
        $supplierMarketRate = $this->exchangeRates->marketRateToNaira($supplierCurrency);
        $supplierEffectiveRate = $supplierMarketRate + $fxBuffer;
        $usdMarketRate = $supplierCurrency === 'USD'
            ? $supplierMarketRate
            : $this->exchangeRates->marketRateToNaira('USD');
        $usdEffectiveRate = $usdMarketRate + $fxBuffer;
        $salesTaxRate = ((int) $rules['pricing.sales_tax_basis_points']) / 10000;
        $customsRate = ((int) $rules['pricing.customs_basis_points']) / 10000;
        $profitMarkupRate = ((int) $rules['pricing.profit_markup_basis_points']) / 10000;
        // Supplier feeds frequently omit weight. Until a reliable weight
        // enrichment step is available, calculate without air cargo instead
        // of leaving the product at a zero price. Supplied weights still use
        // the normal rounded-up cargo charge and maximum-weight validation.
        $chargeableWeightLb = $weightLb === null ? 0.0 : ceil($weightLb);
        $salesTaxSupplierCurrency = $supplierPrice * $salesTaxRate;
        $airCargoUsd = $chargeableWeightLb * (((int) $rules['pricing.air_cargo_usd_cents_per_lb']) / 100);
        $supplierCostNgn = $supplierPrice * $supplierEffectiveRate;
        $salesTaxNgn = $salesTaxSupplierCurrency * $supplierEffectiveRate;
        $airCargoNgn = $airCargoUsd * $usdEffectiveRate;
        $landedCostNgn = $supplierCostNgn + $salesTaxNgn + $airCargoNgn;
        $customsNgn = $landedCostNgn * $customsRate;
        $processingFeeNgn = $this->minorToNaira((int) $rules['pricing.processing_fee_ngn_minor']);
        $totalCostNgn = $landedCostNgn + $customsNgn + $processingFeeNgn;
        $profitNgn = $totalCostNgn * $profitMarkupRate;
        $localShippingNgn = $rules['pricing.include_local_shipping']
            ? $this->minorToNaira((int) $rules['pricing.local_shipping_ngn_minor'])
            : 0.0;
        $rawPriceNgn = $totalCostNgn + $profitNgn + $localShippingNgn;
        $roundingUnitNgn = $this->minorToNaira((int) $rules['pricing.rounding_unit_ngn_minor']);
        $finalPriceNgn = ceil($rawPriceNgn / $roundingUnitNgn) * $roundingUnitNgn;

        return [
            ...$base,
            'status' => $blockedReasons === [] ? 'proposed' : 'blocked',
            'pricing_status' => $blockedReasons === [] ? 'pending_approval' : 'blocked',
            'proposed_price_ngn' => round($finalPriceNgn, 2),
            'cost_breakdown' => [
                'supplier_price' => $supplierPrice,
                'supplier_currency' => $supplierCurrency,
                'supplier_market_rate_ngn' => round($supplierMarketRate, 6),
                'fx_buffer_ngn' => round($fxBuffer, 2),
                'supplier_effective_rate_ngn' => round($supplierEffectiveRate, 6),
                'supplier_cost_ngn' => round($supplierCostNgn, 2),
                'sales_tax_rate' => $salesTaxRate,
                'sales_tax_supplier_currency' => round($salesTaxSupplierCurrency, 4),
                'sales_tax_ngn' => round($salesTaxNgn, 2),
                'weight_missing' => $weightLb === null,
                'chargeable_weight_lb' => $chargeableWeightLb,
                'air_cargo_usd' => round($airCargoUsd, 2),
                'usd_market_rate_ngn' => round($usdMarketRate, 6),
                'air_cargo_ngn' => round($airCargoNgn, 2),
                'landed_cost_ngn' => round($landedCostNgn, 2),
                'customs_rate' => $customsRate,
                'customs_ngn' => round($customsNgn, 2),
                'processing_fee_ngn' => round($processingFeeNgn, 2),
                'total_cost_ngn' => round($totalCostNgn, 2),
                'profit_markup_rate' => $profitMarkupRate,
                'profit_ngn' => round($profitNgn, 2),
                'local_shipping_included' => (bool) $rules['pricing.include_local_shipping'],
                'local_shipping_ngn' => round($localShippingNgn, 2),
                'raw_price_ngn' => round($rawPriceNgn, 2),
                'rounding_unit_ngn' => round($roundingUnitNgn, 2),
                'rounding_adjustment_ngn' => round($finalPriceNgn - $rawPriceNgn, 2),
                'final_price_ngn' => round($finalPriceNgn, 2),
            ],
        ];
    }

    /** @param array<string, mixed> $prepared */
    public function storeQuote(ProductSource $source, array $prepared, ?User $actor = null): ProductPriceQuote
    {
        $breakdown = $prepared['cost_breakdown'];
        $approvedPrice = (float) ($source->approvedPriceQuote?->approved_price_ngn ?? 0);
        $proposedPrice = (float) ($prepared['proposed_price_ngn'] ?? 0);

        if (is_array($breakdown) && $approvedPrice > 0 && $proposedPrice > 0) {
            $changeBasisPoints = (int) round(abs($proposedPrice - $approvedPrice) / $approvedPrice * 10000);
            $breakdown['change_from_approved_basis_points'] = $changeBasisPoints;
            $breakdown['urgent_review'] = $changeBasisPoints >= (int) $prepared['rules_snapshot']['pricing.reprice_threshold_basis_points'];
        }

        $quote = $source->priceQuotes()->create([
            'status' => $prepared['status'],
            'supplier_price' => $prepared['supplier_price'],
            'supplier_currency' => $prepared['supplier_currency'],
            'weight_lb' => $prepared['weight_lb'],
            'delivery_days_min' => $prepared['delivery_days_min'],
            'delivery_days_max' => $prepared['delivery_days_max'],
            'proposed_price_ngn' => $prepared['proposed_price_ngn'],
            'approved_price_ngn' => null,
            'cost_breakdown' => $breakdown,
            'rules_snapshot' => $prepared['rules_snapshot'],
            'rules_version' => $prepared['rules_version'],
            'missing_inputs' => $prepared['missing_inputs'] ?: null,
            'blocked_reasons' => $prepared['blocked_reasons'] ?: null,
            'requested_by' => $actor?->id,
            'quoted_at' => $prepared['quoted_at'],
        ]);

        $source->forceFill([
            'current_price_quote_id' => $quote->id,
            'pricing_status' => $prepared['pricing_status'],
            'price_review_required' => true,
            'final_price_ngn' => $prepared['proposed_price_ngn'],
            'cost_breakdown' => $breakdown,
            'restriction_status' => $prepared['blocked_reasons'] === [] ? 'approved' : 'blocked',
            'validation_status' => match ($prepared['pricing_status']) {
                'pending_inputs' => 'manual_review',
                'blocked' => 'rejected',
                default => 'pricing_review',
            },
            'sync_status' => 'needs_review',
        ])->save();

        if ($prepared['proposed_price_ngn'] !== null && $source->product?->status === 'draft') {
            $source->product->update(['price' => $prepared['proposed_price_ngn']]);
        }

        $this->auditEvents->record(
            action: 'product_price.quote_created',
            actor: $actor,
            subject: $source,
            after: [
                'quote_id' => $quote->id,
                'pricing_status' => $source->pricing_status,
                'proposed_price_ngn' => $quote->proposed_price_ngn,
                'rules_version' => $quote->rules_version,
            ],
        );

        return $quote;
    }

    /** @param array<string, mixed> $overrides */
    public function recalculate(ProductSource $source, array $overrides, ?User $actor = null): ProductSource
    {
        $supplierProduct = array_merge($source->source_snapshot ?? [], [
            'price' => $overrides['supplier_price'] ?? $source->supplier_price,
            'currency' => $overrides['supplier_currency'] ?? $source->supplier_currency,
            'weight_lb' => $overrides['weight_lb'] ?? $source->weight_lb,
            'us_delivery_days_min' => $overrides['delivery_days_min'] ?? $source->delivery_days_min,
            'us_delivery_days_max' => $overrides['delivery_days_max'] ?? $source->delivery_days_max,
        ]);
        $prepared = $this->prepareQuote($supplierProduct, [
            'name' => $source->product?->name,
            'description' => $source->product?->description,
        ]);

        return DB::transaction(function () use ($source, $supplierProduct, $prepared, $actor): ProductSource {
            $lockedSource = ProductSource::query()->with(['product', 'approvedPriceQuote'])->lockForUpdate()->findOrFail($source->id);
            $lockedSource->forceFill([
                'supplier_price' => $supplierProduct['price'] ?? null,
                'supplier_currency' => $supplierProduct['currency'] ?? null,
                'weight_lb' => $supplierProduct['weight_lb'] ?? null,
                'delivery_days_min' => $supplierProduct['us_delivery_days_min'] ?? null,
                'delivery_days_max' => $supplierProduct['us_delivery_days_max'] ?? null,
                'last_synced_at' => now(),
            ])->save();
            $this->storeQuote($lockedSource, $prepared, $actor);

            return $lockedSource->fresh(['product', 'currentPriceQuote', 'approvedPriceQuote']);
        });
    }

    /** @param array<string, mixed> $supplierProduct */
    public function refreshFromSupplier(ProductSource $source, array $supplierProduct): ProductSource
    {
        if ($this->positiveFloat($supplierProduct['weight_lb'] ?? null) === null && (float) $source->weight_lb > 0) {
            $supplierProduct['weight_lb'] = $source->weight_lb;
        }

        if ($this->positiveInteger($supplierProduct['us_delivery_days_max'] ?? null) === null && (int) $source->delivery_days_max > 0) {
            $supplierProduct['us_delivery_days_min'] = $source->delivery_days_min;
            $supplierProduct['us_delivery_days_max'] = $source->delivery_days_max;
        }

        $source->source_snapshot = $supplierProduct;
        $source->save();

        return $this->recalculate($source, [
            'supplier_price' => $supplierProduct['price'] ?? $source->supplier_price,
            'supplier_currency' => $supplierProduct['currency'] ?? $source->supplier_currency,
            'weight_lb' => $supplierProduct['weight_lb'] ?? $source->weight_lb,
            'delivery_days_min' => $supplierProduct['us_delivery_days_min'] ?? $source->delivery_days_min,
            'delivery_days_max' => $supplierProduct['us_delivery_days_max'] ?? $source->delivery_days_max,
        ]);
    }

    public function approve(ProductSource $source, User $actor, ?int $quoteId = null): ProductSource
    {
        return DB::transaction(function () use ($source, $actor, $quoteId): ProductSource {
            $lockedSource = ProductSource::query()->with('product')->lockForUpdate()->findOrFail($source->id);
            $quote = ProductPriceQuote::query()
                ->where('product_source_id', $lockedSource->id)
                ->whereKey($quoteId ?? $lockedSource->current_price_quote_id)
                ->lockForUpdate()
                ->first();

            if (! $quote || $quote->status !== 'proposed' || (float) $quote->proposed_price_ngn <= 0) {
                throw ValidationException::withMessages([
                    'pricing' => ['A complete proposed price must be calculated before approval.'],
                ]);
            }

            if ($lockedSource->approved_price_quote_id && $lockedSource->approved_price_quote_id !== $quote->id) {
                ProductPriceQuote::query()
                    ->whereKey($lockedSource->approved_price_quote_id)
                    ->where('status', 'approved')
                    ->update(['status' => 'superseded', 'updated_at' => now()]);
            }

            $quote->forceFill([
                'status' => 'approved',
                'approved_price_ngn' => $quote->proposed_price_ngn,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ])->save();

            $lockedSource->forceFill([
                'approved_price_quote_id' => $quote->id,
                'pricing_status' => 'approved',
                'pricing_approved_by' => $actor->id,
                'pricing_approved_at' => now(),
                'price_review_required' => false,
                'final_price_ngn' => $quote->approved_price_ngn,
                'cost_breakdown' => $quote->cost_breakdown,
                'validation_status' => 'approved',
                'sync_status' => 'imported',
            ])->save();
            $lockedSource->product->update(['price' => $quote->approved_price_ngn, 'currency' => 'NGN']);

            $this->auditEvents->record(
                action: 'product_price.quote_approved',
                actor: $actor,
                subject: $lockedSource,
                after: [
                    'quote_id' => $quote->id,
                    'approved_price_ngn' => $quote->approved_price_ngn,
                    'rules_version' => $quote->rules_version,
                ],
            );

            return $lockedSource->fresh(['product', 'currentPriceQuote', 'approvedPriceQuote']);
        });
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function rules(): array
    {
        $settings = BusinessSetting::query()
            ->where('group', 'pricing')
            ->whereIn('key', self::REQUIRED_RULES)
            ->get()
            ->keyBy('key');

        $missing = collect(self::REQUIRED_RULES)->reject(fn (string $key): bool => $settings->has($key));

        if ($missing->isNotEmpty()) {
            throw new LogicException('Missing pricing settings: '.$missing->implode(', '));
        }

        $rules = $settings->mapWithKeys(fn (BusinessSetting $setting): array => [$setting->key => $setting->value])->all();
        $versions = $settings->mapWithKeys(fn (BusinessSetting $setting): array => [$setting->key => $setting->version])->sortKeys()->all();

        return [$rules, hash('sha256', json_encode($versions, JSON_THROW_ON_ERROR))];
    }

    /** @param array<int, string> $keywords */
    private function restrictedKeyword(string $text, array $keywords): ?string
    {
        $haystack = ' '.Str::lower($text).' ';

        foreach ($keywords as $keyword) {
            $normalized = trim(Str::lower((string) $keyword));

            if ($normalized !== '' && str_contains($haystack, $normalized)) {
                return $normalized;
            }
        }

        return null;
    }

    private function positiveFloat(mixed $value): ?float
    {
        return is_numeric($value) && (float) $value > 0 ? (float) $value : null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function minorToNaira(int $minor): float
    {
        return $minor / 100;
    }
}
