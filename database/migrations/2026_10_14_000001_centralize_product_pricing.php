<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->createPricingSettings();

        Schema::create('product_price_quotes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_source_id')->constrained()->cascadeOnDelete();
            $table->string('status', 30)->index();
            $table->decimal('supplier_price', 15, 4)->nullable();
            $table->char('supplier_currency', 3)->nullable();
            $table->decimal('weight_lb', 12, 4)->nullable();
            $table->unsignedSmallInteger('delivery_days_min')->nullable();
            $table->unsignedSmallInteger('delivery_days_max')->nullable();
            $table->decimal('proposed_price_ngn', 15, 2)->nullable();
            $table->decimal('approved_price_ngn', 15, 2)->nullable();
            $table->json('cost_breakdown')->nullable();
            $table->json('rules_snapshot');
            $table->string('rules_version', 64);
            $table->json('missing_inputs')->nullable();
            $table->json('blocked_reasons')->nullable();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('quoted_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index(['product_source_id', 'quoted_at']);
        });

        Schema::table('product_sources', function (Blueprint $table): void {
            $table->string('pricing_status', 30)->default('pending_inputs')->index();
            $table->foreignId('current_price_quote_id')->nullable()->constrained('product_price_quotes')->nullOnDelete();
            $table->foreignId('approved_price_quote_id')->nullable()->constrained('product_price_quotes')->nullOnDelete();
            $table->foreignId('pricing_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('pricing_approved_at')->nullable();
            $table->boolean('price_review_required')->default(true)->index();
        });

        $this->backfillExistingSources();
    }

    public function down(): void
    {
        Schema::table('product_sources', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_price_quote_id');
            $table->dropConstrainedForeignId('approved_price_quote_id');
            $table->dropConstrainedForeignId('pricing_approved_by');
            $table->dropColumn(['pricing_status', 'pricing_approved_at', 'price_review_required']);
        });

        Schema::dropIfExists('product_price_quotes');
        DB::table('business_settings')->where('group', 'pricing')->delete();
    }

    private function createPricingSettings(): void
    {
        $now = now();
        $settings = [
            ['key' => 'pricing.sales_tax_basis_points', 'label' => 'Supplier sales tax', 'description' => 'Sales tax applied to the supplier price, in basis points.', 'type' => 'integer', 'value' => 1000, 'constraints' => ['min' => 0, 'max' => 5000]],
            ['key' => 'pricing.air_cargo_usd_cents_per_lb', 'label' => 'Air cargo per pound', 'description' => 'International air-cargo cost in US cents per chargeable pound.', 'type' => 'integer', 'value' => 500, 'constraints' => ['min' => 0, 'max' => 100000]],
            ['key' => 'pricing.customs_basis_points', 'label' => 'Customs and clearing rate', 'description' => 'Customs and clearing allowance applied to landed cost, in basis points.', 'type' => 'integer', 'value' => 0, 'constraints' => ['min' => 0, 'max' => 10000]],
            ['key' => 'pricing.processing_fee_ngn_minor', 'label' => 'Processing fee', 'description' => 'Fixed processing allowance in kobo.', 'type' => 'integer', 'value' => 0, 'constraints' => ['min' => 0, 'max' => 100000000]],
            ['key' => 'pricing.profit_markup_basis_points', 'label' => 'Profit markup', 'description' => 'Profit markup applied to total landed cost, in basis points.', 'type' => 'integer', 'value' => 20000, 'constraints' => ['min' => 0, 'max' => 100000]],
            ['key' => 'pricing.fx_buffer_ngn_minor', 'label' => 'Exchange-rate buffer', 'description' => 'Naira buffer added to each foreign-currency market-rate unit, in kobo.', 'type' => 'integer', 'value' => 10000, 'constraints' => ['min' => 0, 'max' => 100000]],
            ['key' => 'pricing.rounding_unit_ngn_minor', 'label' => 'Selling-price rounding unit', 'description' => 'Final prices are rounded upward to this number of kobo.', 'type' => 'integer', 'value' => 100000, 'constraints' => ['min' => 1, 'max' => 10000000]],
            ['key' => 'pricing.max_weight_ounces', 'label' => 'Maximum supplier weight', 'description' => 'Maximum product weight allowed for sourcing, in ounces.', 'type' => 'integer', 'value' => 80, 'constraints' => ['min' => 1, 'max' => 1600]],
            ['key' => 'pricing.max_us_delivery_days', 'label' => 'Maximum US delivery time', 'description' => 'Maximum supplier-to-US delivery estimate allowed.', 'type' => 'integer', 'value' => 7, 'constraints' => ['min' => 1, 'max' => 60]],
            ['key' => 'pricing.reprice_threshold_basis_points', 'label' => 'Price-review threshold', 'description' => 'Percentage change highlighted for urgent review, in basis points.', 'type' => 'integer', 'value' => 500, 'constraints' => ['min' => 0, 'max' => 10000]],
            ['key' => 'pricing.include_local_shipping', 'label' => 'Include Nigerian delivery in product price', 'description' => 'When disabled, Nigerian last-mile delivery is quoted during checkout.', 'type' => 'boolean', 'value' => false, 'constraints' => null],
            ['key' => 'pricing.local_shipping_ngn_minor', 'label' => 'Flat Nigerian delivery allowance', 'description' => 'Flat Nigerian delivery amount in kobo when inclusion is enabled.', 'type' => 'integer', 'value' => 500000, 'constraints' => ['min' => 0, 'max' => 100000000]],
            ['key' => 'pricing.restricted_keywords', 'label' => 'Restricted sourcing keywords', 'description' => 'Products containing these terms require rejection or manual correction.', 'type' => 'array', 'value' => ['perfume', 'fragrance spray', 'aerosol', 'flammable', 'supplement', 'vitamin', 'medical', 'prescription', 'weapon', 'stun gun', 'knife', 'firearm', 'battery pack', 'hoverboard', 'television', 'refrigerator', 'glass fragile', 'fragile glass', '110v', '110 volt', 'luxury designer', 'replica', 'inspired by', 'counterfeit', 'used phone locked', 'cologne spray'], 'constraints' => ['max_items' => 100, 'distinct' => true, 'item_type' => 'string']],
        ];

        DB::table('business_settings')->upsert(array_map(
            fn (array $setting): array => [
                ...$setting,
                'group' => 'pricing',
                'value' => json_encode($setting['value'], JSON_THROW_ON_ERROR),
                'constraints' => $setting['constraints'] === null ? null : json_encode($setting['constraints'], JSON_THROW_ON_ERROR),
                'is_public' => false,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $settings,
        ), ['key'], ['label', 'description', 'type', 'value', 'constraints', 'is_public', 'updated_at']);
    }

    private function backfillExistingSources(): void
    {
        DB::table('product_sources')->orderBy('id')->each(function (object $source): void {
            $breakdown = is_string($source->cost_breakdown)
                ? json_decode($source->cost_breakdown, true)
                : $source->cost_breakdown;
            $breakdown = is_array($breakdown) ? $breakdown : [];
            $fullyPriced = isset($breakdown['landed_cost_ngn']) && (float) $source->final_price_ngn > 0;
            $missingInputs = array_values(array_filter([
                (float) $source->supplier_price > 0 ? null : 'supplier_price',
                filled($source->supplier_currency) ? null : 'supplier_currency',
                (float) $source->weight_lb > 0 ? null : 'weight_lb',
                (int) $source->delivery_days_max > 0 ? null : 'delivery_days_max',
            ]));
            $now = now();

            $quoteId = DB::table('product_price_quotes')->insertGetId([
                'product_source_id' => $source->id,
                'status' => $fullyPriced ? 'approved' : 'pending_inputs',
                'supplier_price' => $source->supplier_price,
                'supplier_currency' => $source->supplier_currency,
                'weight_lb' => $source->weight_lb,
                'delivery_days_min' => $source->delivery_days_min,
                'delivery_days_max' => $source->delivery_days_max,
                'proposed_price_ngn' => $fullyPriced ? $source->final_price_ngn : null,
                'approved_price_ngn' => $fullyPriced ? $source->final_price_ngn : null,
                'cost_breakdown' => $breakdown === [] ? null : json_encode($breakdown, JSON_THROW_ON_ERROR),
                'rules_snapshot' => json_encode(['legacy_import' => true], JSON_THROW_ON_ERROR),
                'rules_version' => 'legacy',
                'missing_inputs' => $missingInputs === [] ? null : json_encode($missingInputs, JSON_THROW_ON_ERROR),
                'blocked_reasons' => null,
                'requested_by' => null,
                'approved_by' => null,
                'quoted_at' => $source->last_synced_at ?? $source->created_at ?? $now,
                'approved_at' => $fullyPriced ? $now : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('product_sources')->where('id', $source->id)->update([
                'pricing_status' => $fullyPriced ? 'approved' : 'pending_inputs',
                'current_price_quote_id' => $quoteId,
                'approved_price_quote_id' => $fullyPriced ? $quoteId : null,
                'pricing_approved_at' => $fullyPriced ? $now : null,
                'price_review_required' => ! $fullyPriced,
            ]);
        });
    }
};
