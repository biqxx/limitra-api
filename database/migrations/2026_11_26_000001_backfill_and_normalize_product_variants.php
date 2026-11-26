<?php

use App\Models\Product\ProductSource;
use App\Models\Product\ProductVariant;
use App\Services\Catalog\CatalogCache;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_variants')) {
            return;
        }

        // 1. Normalize existing product_variants attribute maps (repairs shifted Amazon Twister keys)
        ProductVariant::query()->chunkById(200, function ($variants): void {
            foreach ($variants as $variant) {
                $normalized = ProductVariant::normalizeAttributeMap($variant->attributes);
                if ($normalized !== [] && $normalized !== $variant->attributes) {
                    $variant->update(['attributes' => $normalized]);
                }
            }
        });

        // 2. Backfill product_variants for any ProductSource whose Product has 0 variants in product_variants
        if (Schema::hasTable('product_sources')) {
            $sources = ProductSource::query()
                ->with(['product.variants'])
                ->whereNotNull('product_id')
                ->get();

            foreach ($sources as $source) {
                $product = $source->product;
                if (! $product || $product->variants->isNotEmpty()) {
                    continue;
                }

                $rawVariants = is_array($source->variants) && $source->variants !== []
                    ? $source->variants
                    : (is_array($source->source_snapshot['variants'] ?? null) ? $source->source_snapshot['variants'] : []);

                if ($rawVariants === []) {
                    continue;
                }

                $baseSupplierPrice = (float) ($source->supplier_price ?? 0);
                $baseNgnPrice = (float) ($source->final_price_ngn ?? $product->price ?? 0);
                $usedSkus = [];

                foreach ($rawVariants as $index => $rv) {
                    if (! is_array($rv)) {
                        continue;
                    }

                    $rawAttrs = is_array($rv['selected_options'] ?? null) && $rv['selected_options'] !== []
                        ? $rv['selected_options']
                        : (is_array($rv['attributes'] ?? null) ? $rv['attributes'] : []);
                    $attributes = ProductVariant::normalizeAttributeMap(
                        $rawAttrs,
                        isset($rv['label']) ? (string) $rv['label'] : null,
                        isset($rv['value']) ? (string) $rv['value'] : null,
                    );
                    if ($attributes === []) {
                        continue;
                    }

                    $ngnPrice = isset($rv['proposed_price_ngn']) && is_numeric($rv['proposed_price_ngn']) && (float) $rv['proposed_price_ngn'] > 0
                        ? round((float) $rv['proposed_price_ngn'], 2)
                        : null;

                    if ($ngnPrice === null && isset($rv['price']) && is_numeric($rv['price']) && (float) $rv['price'] > 0 && $baseSupplierPrice > 0 && $baseNgnPrice > 0) {
                        $varSupplierPrice = (float) $rv['price'];
                        $ngnPrice = abs($varSupplierPrice - $baseSupplierPrice) < 0.01
                            ? round($baseNgnPrice, 2)
                            : round(($varSupplierPrice / $baseSupplierPrice) * $baseNgnPrice, 2);
                    }

                    if ($ngnPrice === null || $ngnPrice <= 0) {
                        $ngnPrice = $baseNgnPrice > 0 ? round($baseNgnPrice, 2) : round((float) $product->price, 2);
                    }

                    $rawSku = trim((string) ($rv['sku'] ?? $rv['id'] ?? ''));
                    $baseSku = $rawSku !== '' ? $rawSku : "{$source->supplier}-{$source->external_product_id}-V".($index + 1);
                    $baseSku = Str::upper((string) preg_replace('/[^a-zA-Z0-9-]+/', '-', $baseSku));
                    $baseSku = Str::limit(trim($baseSku, '-'), 90, '') ?: 'VAR-'.Str::upper(Str::random(8));
                    $variantSku = $baseSku;
                    $suffix = 2;
                    while (in_array($variantSku, $usedSkus, true) || ProductVariant::query()->where('sku', $variantSku)->exists()) {
                        $variantSku = Str::limit($baseSku, 85, '').'-'.$suffix++;
                    }
                    $usedSkus[] = $variantSku;

                    $available = array_key_exists('available', $rv) ? (bool) $rv['available'] : true;
                    $stock = isset($rv['stock']) && is_numeric($rv['stock'])
                        ? max(0, (int) $rv['stock'])
                        : ($available ? 10 : 0);
                    $images = is_array($rv['images'] ?? null) ? array_values(array_filter($rv['images'], 'is_string')) : [];

                    ProductVariant::create([
                        'product_id' => $product->id,
                        'sku' => $variantSku,
                        'attributes' => $attributes,
                        'images' => $images !== [] ? $images : null,
                        'price' => $ngnPrice,
                        'stock' => $stock,
                        'status' => $available ? 'active' : 'disabled',
                    ]);
                }
            }
        }

        try {
            app(CatalogCache::class)->invalidateProducts();
        } catch (\Throwable) {
            // ignore cache error during migration
        }
    }

    public function down(): void
    {
        // Non-destructive data normalization migration
    }
};
