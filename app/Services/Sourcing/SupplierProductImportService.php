<?php

namespace App\Services\Sourcing;

use App\Models\Image;
use App\Models\Product\Product;
use App\Models\Product\ProductSource;
use App\Models\User;
use App\Services\Currency\CurrencyExchangeService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

class SupplierProductImportService
{
    public function __construct(
        private readonly SupplierServiceClient $client,
        private readonly CurrencyExchangeService $exchangeRates,
    ) {}

    public function import(array $attributes, User $admin): ProductSource
    {
        $supplier = $attributes['source'];
        $externalProductId = $attributes['external_product_id'];

        if ($this->alreadyImported($supplier, $externalProductId)) {
            throw new DomainException('This supplier product has already been imported.');
        }

        $supplierProduct = $this->client->import($supplier, $externalProductId);

        return $this->importResolved($attributes, $admin, $supplierProduct);
    }

    /** @param array<string, mixed> $attributes @param array<string, mixed> $supplierProduct */
    public function importResolved(
        array $attributes,
        User $admin,
        array $supplierProduct,
        bool $needsPricingReview = false,
    ): ProductSource {
        $supplier = $attributes['source'];
        $externalProductId = $attributes['external_product_id'];
        [$price, $conversion] = $this->resolveNairaPrice($supplierProduct);

        if ($price <= 0 && ! $needsPricingReview) {
            throw new UnexpectedValueException('The supplier did not return a valid NGN selling price.');
        }

        return DB::transaction(function () use ($attributes, $admin, $supplier, $externalProductId, $supplierProduct, $price, $conversion, $needsPricingReview) {
            if ($this->alreadyImported($supplier, $externalProductId)) {
                throw new DomainException('This supplier product has already been imported.');
            }

            $name = trim((string) ($attributes['name'] ?? $supplierProduct['title'] ?? ''));

            if ($name === '') {
                throw new UnexpectedValueException('The supplier product does not have a valid title.');
            }

            $imageUrl = $this->secureUrl($supplierProduct['image_url'] ?? null);
            $product = Product::create([
                'category_id' => $attributes['category_id'],
                'subcategory_id' => $attributes['subcategory_id'] ?? null,
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'brand' => $attributes['brand'] ?? $supplierProduct['brand'] ?? null,
                'sku' => $this->uniqueSku($attributes['sku'] ?? null, $supplier, $externalProductId),
                'description' => array_key_exists('description', $attributes)
                    ? $attributes['description']
                    : ($supplierProduct['description'] ?? null),
                'price' => $price,
                'currency' => 'NGN',
                'stock' => 0,
                'status' => 'draft',
                'images' => $imageUrl ? [$imageUrl] : null,
                'created_by' => $admin->id,
            ]);

            $product->specifications()->createMany(array_map(
                fn (array $specification, int $position): array => [
                    'name' => $specification['name'],
                    'value' => $specification['value'],
                    'sort_order' => $position,
                ],
                $attributes['specifications'] ?? [],
                array_keys($attributes['specifications'] ?? []),
            ));

            if ($imageUrl) {
                Image::create([
                    'imageable_id' => $product->id,
                    'imageable_type' => Product::class,
                    'type' => 'product_image',
                    'path' => $imageUrl,
                    'alt' => $name,
                    'sort_order' => 0,
                ]);
            }

            $costBreakdown = is_array($supplierProduct['cost_breakdown'] ?? null)
                ? $supplierProduct['cost_breakdown']
                : [];

            if ($conversion !== null) {
                $costBreakdown['currency_conversion'] = $conversion;
            }

            $source = ProductSource::create([
                'product_id' => $product->id,
                'supplier' => $supplier,
                'external_product_id' => $externalProductId,
                'supplier_url' => $supplierProduct['product_url'] ?? null,
                'supplier_price' => $supplierProduct['price'] ?? null,
                'supplier_currency' => $supplierProduct['currency'] ?? null,
                'available' => (bool) ($supplierProduct['available'] ?? false),
                'weight_lb' => $supplierProduct['weight_lb'] ?? null,
                'delivery_days_min' => $supplierProduct['us_delivery_days_min'] ?? null,
                'delivery_days_max' => $supplierProduct['us_delivery_days_max'] ?? null,
                'restriction_status' => $supplierProduct['restriction_status'] ?? null,
                'validation_status' => $needsPricingReview ? 'manual_review' : ($supplierProduct['validation_status'] ?? null),
                'final_price_ngn' => $price > 0 ? $price : null,
                'variants' => $supplierProduct['variants'] ?? null,
                'cost_breakdown' => $costBreakdown !== [] ? $costBreakdown : null,
                'source_snapshot' => $supplierProduct,
                'sync_status' => $needsPricingReview ? 'needs_review' : 'imported',
                'last_synced_at' => now(),
            ]);

            return $source->load(['product.category', 'product.subcategory', 'product.productImages', 'product.specifications']);
        });
    }

    /** @param array<string, mixed> $supplierProduct @return array{0: float, 1: array<string, float|string>|null} */
    private function resolveNairaPrice(array $supplierProduct): array
    {
        $finalPrice = (float) ($supplierProduct['final_price_ngn'] ?? 0);

        if ($finalPrice > 0) {
            return [$finalPrice, null];
        }

        $supplierPrice = (float) ($supplierProduct['price'] ?? 0);
        $supplierCurrency = trim((string) ($supplierProduct['currency'] ?? ''));

        if ($supplierPrice <= 0 || $supplierCurrency === '') {
            return [0.0, null];
        }

        $conversion = $this->exchangeRates->quoteToNaira($supplierPrice, $supplierCurrency);

        return [$conversion['converted_amount'], $conversion];
    }

    private function secureUrl(mixed $value): ?string
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        return str_starts_with($value, 'http://') ? 'https://'.substr($value, 7) : $value;
    }

    private function alreadyImported(string $supplier, string $externalProductId): bool
    {
        return ProductSource::query()
            ->where('supplier', $supplier)
            ->where('external_product_id', $externalProductId)
            ->exists();
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'supplier-product';
        $slug = $base;
        $suffix = 2;

        while (Product::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function uniqueSku(?string $requestedSku, string $supplier, string $externalProductId): string
    {
        $base = $requestedSku ?: $supplier.'-'.$externalProductId;
        $base = Str::upper((string) preg_replace('/[^a-zA-Z0-9-]+/', '-', $base));
        $base = Str::limit(trim($base, '-'), 240, '');
        $base = $base !== '' ? $base : Str::upper($supplier).'-'.Str::random(10);
        $sku = $base;
        $suffix = 2;

        while (Product::withTrashed()->where('sku', $sku)->exists()) {
            $sku = Str::limit($base, 240, '').'-'.$suffix++;
        }

        return $sku;
    }
}
