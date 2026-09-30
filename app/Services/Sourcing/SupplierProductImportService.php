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
    public function __construct(private readonly SupplierServiceClient $client) {}

    public function import(array $attributes, User $admin): ProductSource
    {
        $supplier = $attributes['source'];
        $externalProductId = $attributes['external_product_id'];

        if ($this->alreadyImported($supplier, $externalProductId)) {
            throw new DomainException('This supplier product has already been imported.');
        }

        $supplierProduct = $this->client->import($supplier, $externalProductId);
        $price = (float) ($supplierProduct['final_price_ngn'] ?? 0);

        if ($price <= 0) {
            throw new UnexpectedValueException('The supplier did not return a valid NGN selling price.');
        }

        return DB::transaction(function () use ($attributes, $admin, $supplier, $externalProductId, $supplierProduct, $price) {
            if ($this->alreadyImported($supplier, $externalProductId)) {
                throw new DomainException('This supplier product has already been imported.');
            }

            $name = trim((string) ($supplierProduct['title'] ?? ''));

            if ($name === '') {
                throw new UnexpectedValueException('The supplier product does not have a valid title.');
            }

            $imageUrl = filter_var($supplierProduct['image_url'] ?? null, FILTER_VALIDATE_URL) ?: null;
            $product = Product::create([
                'category_id' => $attributes['category_id'],
                'subcategory_id' => $attributes['subcategory_id'] ?? null,
                'name' => $name,
                'slug' => $this->uniqueSlug($name),
                'brand' => $attributes['brand'] ?? null,
                'sku' => $this->uniqueSku($attributes['sku'] ?? null, $supplier, $externalProductId),
                'description' => $supplierProduct['description'] ?? null,
                'price' => $price,
                'currency' => 'NGN',
                'stock' => 0,
                'status' => 'draft',
                'images' => $imageUrl ? [$imageUrl] : null,
                'created_by' => $admin->id,
            ]);

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
                'restriction_status' => $supplierProduct['restriction_status'] ?? null,
                'validation_status' => $supplierProduct['validation_status'] ?? null,
                'final_price_ngn' => $price,
                'variants' => $supplierProduct['variants'] ?? null,
                'cost_breakdown' => $supplierProduct['cost_breakdown'] ?? null,
                'source_snapshot' => $supplierProduct,
                'sync_status' => 'imported',
                'last_synced_at' => now(),
            ]);

            return $source->load(['product.category', 'product.subcategory', 'product.productImages']);
        });
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
