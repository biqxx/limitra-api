<?php

namespace App\Services\Sourcing;

use App\Models\Image;
use App\Models\Product\Product;
use App\Models\Product\ProductSource;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UnexpectedValueException;

class SupplierProductImportService
{
    public function __construct(
        private readonly SupplierServiceClient $client,
        private readonly ProductPricingService $pricing,
    ) {}

    public function import(array $attributes, User $admin): ProductSource
    {
        $supplier = $attributes['source'];
        $externalProductId = $attributes['external_product_id'];

        if ($this->alreadyImported($supplier, $externalProductId)) {
            throw new DomainException('This supplier product has already been imported.');
        }

        $supplierProduct = $this->client->details($supplier, $externalProductId);

        return $this->importResolved($attributes, $admin, $supplierProduct);
    }

    /** @param array<string, mixed> $attributes @param array<string, mixed> $supplierProduct */
    public function importResolved(
        array $attributes,
        User $admin,
        array $supplierProduct,
    ): ProductSource {
        $supplier = $attributes['source'];
        $externalProductId = $attributes['external_product_id'];
        $name = trim((string) ($attributes['name'] ?? $supplierProduct['title'] ?? ''));

        if ($name === '') {
            throw new UnexpectedValueException('The supplier product does not have a valid title.');
        }

        $description = array_key_exists('description', $attributes)
            ? $attributes['description']
            : ($supplierProduct['description'] ?? null);
        $preparedQuote = $this->pricing->prepareQuote($supplierProduct, [
            'name' => $name,
            'description' => $description,
        ]);

        return DB::transaction(function () use ($attributes, $admin, $supplier, $externalProductId, $supplierProduct, $preparedQuote, $name, $description) {
            if ($this->alreadyImported($supplier, $externalProductId)) {
                throw new DomainException('This supplier product has already been imported.');
            }

            $imageUrl = $this->secureUrl($supplierProduct['image_url'] ?? null);
            $product = Product::create([
                'category_id' => $attributes['category_id'],
                'subcategory_id' => $attributes['subcategory_id'] ?? null,
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'brand' => $attributes['brand'] ?? $supplierProduct['brand'] ?? null,
                'sku' => $this->uniqueSku($attributes['sku'] ?? null, $supplier, $externalProductId),
                'description' => $description,
                'price' => $preparedQuote['proposed_price_ngn'] ?? 0,
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
                'restriction_status' => null,
                'validation_status' => null,
                'final_price_ngn' => null,
                'variants' => $supplierProduct['variants'] ?? null,
                'cost_breakdown' => null,
                'source_snapshot' => $supplierProduct,
                'sync_status' => 'needs_review',
                'last_synced_at' => now(),
            ]);
            $this->pricing->storeQuote($source, $preparedQuote, $admin);

            return $source->load([
                'product.category',
                'product.subcategory',
                'product.productImages',
                'product.specifications',
                'currentPriceQuote',
                'approvedPriceQuote',
            ]);
        });
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
