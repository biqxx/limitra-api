<?php

namespace App\Services\Sourcing;

use App\Exceptions\SupplierProductDataException;
use App\Models\Image;
use App\Models\Product\Product;
use App\Models\Product\ProductSource;
use App\Models\Product\ProductVariant;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
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
        $this->assertSupplierProductCanBeImported($supplierProduct);

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
            'category_id' => $attributes['category_id'] ?? null,
            'subcategory_id' => $attributes['subcategory_id'] ?? null,
        ]);

        return DB::transaction(function () use ($attributes, $admin, $supplier, $externalProductId, $supplierProduct, $preparedQuote, $name, $description) {
            if ($this->alreadyImported($supplier, $externalProductId)) {
                throw new DomainException('This supplier product has already been imported.');
            }

            $remoteImageUrls = $this->extractImageUrls($supplierProduct);
            $publishNow = (bool) ($attributes['publish'] ?? false);
            $defaultStock = max(0, (int) ($attributes['stock'] ?? ($publishNow ? 10 : 0)));

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
                'stock' => $defaultStock,
                'status' => 'draft',
                'seo_meta' => $attributes['seo_meta'] ?? null,
                'images' => $remoteImageUrls !== [] ? $remoteImageUrls : null,
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

            $variantsInput = is_array($attributes['variants'] ?? null)
                ? $attributes['variants']
                : ($supplierProduct['variants'] ?? []);
            $syncedVariants = $this->syncProductVariants($product, $variantsInput, $supplier, $externalProductId, $supplierProduct, $preparedQuote);

            if ($remoteImageUrls !== []) {
                $storedUrls = [];
                foreach ($remoteImageUrls as $position => $remoteUrl) {
                    $finalUrl = $this->downloadAndStoreImage($remoteUrl, $product->id, $position);
                    $storedUrls[] = $finalUrl;
                    Image::create([
                        'imageable_id' => $product->id,
                        'imageable_type' => Product::class,
                        'type' => 'product_image',
                        'path' => $finalUrl,
                        'alt' => $name,
                        'sort_order' => $position,
                    ]);
                }
                $product->update(['images' => $storedUrls]);
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
                'variants' => $syncedVariants !== [] ? $syncedVariants : ($supplierProduct['variants'] ?? null),
                'cost_breakdown' => null,
                'source_snapshot' => $supplierProduct,
                'sync_status' => 'needs_review',
                'last_synced_at' => now(),
            ]);
            $quote = $this->pricing->storeQuote($source, $preparedQuote, $admin);

            if ($publishNow && (float) ($quote->proposed_price_ngn ?? 0) > 0) {
                $this->pricing->approve($source, $admin, $quote->id);
                $product->update([
                    'status' => 'active',
                    'stock' => max(1, $defaultStock),
                ]);
            }

            return $source->load([
                'product.category',
                'product.subcategory',
                'product.productImages',
                'product.specifications',
                'product.variants',
                'currentPriceQuote',
                'approvedPriceQuote',
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $supplierProduct
     * @return array<int, string>
     */
    private function extractImageUrls(array $supplierProduct): array
    {
        $candidates = [];

        if (! empty($supplierProduct['image_url'])) {
            $candidates[] = $supplierProduct['image_url'];
        }

        if (isset($supplierProduct['images']) && is_array($supplierProduct['images'])) {
            foreach ($supplierProduct['images'] as $item) {
                if (is_string($item)) {
                    $candidates[] = $item;
                } elseif (is_array($item) && ! empty($item['url'])) {
                    $candidates[] = $item['url'];
                }
            }
        }

        $urls = [];
        foreach ($candidates as $candidate) {
            $secured = $this->secureUrl($candidate);
            if ($secured !== null && ! in_array($secured, $urls, true)) {
                $urls[] = $secured;
            }
            if (count($urls) >= 10) {
                break;
            }
        }

        return $urls;
    }

    public function downloadAndStoreImage(string $remoteUrl, int $productId, int $position = 0): string
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
                    'Accept' => 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
                ])
                ->get($remoteUrl);

            if (! $response->successful()) {
                return $remoteUrl;
            }

            $body = $response->body();
            if (strlen($body) < 128) {
                return $remoteUrl;
            }

            $contentType = strtolower((string) $response->header('Content-Type'));
            $ext = match (true) {
                str_contains($contentType, 'webp') => 'webp',
                str_contains($contentType, 'png') => 'png',
                str_contains($contentType, 'gif') => 'gif',
                str_contains($contentType, 'jpeg'), str_contains($contentType, 'jpg') => 'jpg',
                default => null,
            };

            if ($ext === null) {
                $pathExt = strtolower(pathinfo((string) parse_url($remoteUrl, PHP_URL_PATH), PATHINFO_EXTENSION));
                $ext = in_array($pathExt, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)
                    ? ($pathExt === 'jpeg' ? 'jpg' : $pathExt)
                    : 'jpg';
            }

            $hash = substr(sha1($remoteUrl), 0, 12);
            $storagePath = sprintf('products/%d/%d-%s.%s', $productId, $position, $hash, $ext);
            Storage::disk('public')->put($storagePath, $body);

            return rtrim((string) config('app.url'), '/').'/media/'.$storagePath;
        } catch (Throwable) {
            return $remoteUrl;
        }
    }

    private function secureUrl(mixed $value): ?string
    {
        if (! is_string($value) || ! filter_var($value, FILTER_VALIDATE_URL)) {
            return null;
        }

        return str_starts_with($value, 'http://') ? 'https://'.substr($value, 7) : $value;
    }

    /** @param array<string, mixed> $supplierProduct */
    private function assertSupplierProductCanBeImported(array $supplierProduct): void
    {
        $price = $supplierProduct['price'] ?? null;
        $currency = strtoupper(trim((string) ($supplierProduct['currency'] ?? '')));
        $missingFields = [];

        if (! is_numeric($price) || (float) $price <= 0) {
            $missingFields[] = 'price';
        }

        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            $missingFields[] = 'currency';
        }

        $dataQuality = $supplierProduct['data_quality'] ?? null;
        if (is_array($dataQuality) && ($dataQuality['price_verified'] ?? null) === false) {
            $missingFields[] = 'price';
        }

        if ($missingFields !== []) {
            throw SupplierProductDataException::priceUnavailable(array_values(array_unique($missingFields)));
        }
    }

    private function alreadyImported(string $supplier, string $externalProductId): bool
    {
        return ProductSource::query()
            ->where('supplier', $supplier)
            ->where('external_product_id', $externalProductId)
            ->exists();
    }

    /**
     * @param  array<int, mixed>  $variants
     * @param  array<string, mixed>  $supplierProduct
     * @param  array<string, mixed>  $preparedQuote
     * @return array<int, array<string, mixed>>
     */
    private function syncProductVariants(
        Product $product,
        array $variants,
        string $supplier,
        string $externalProductId,
        array $supplierProduct,
        array $preparedQuote,
    ): array {
        if ($variants === []) {
            return [];
        }

        $baseSupplierPrice = isset($supplierProduct['price']) && is_numeric($supplierProduct['price'])
            ? (float) $supplierProduct['price']
            : 0.0;
        $baseNgnPrice = (float) ($preparedQuote['proposed_price_ngn'] ?? $product->price ?? 0);
        $usedSkus = [];
        $storedImageCache = [];
        $quoteBySupplierPrice = [];
        $syncedVariants = [];

        foreach ($variants as $index => $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $attributes = is_array($variant['selected_options'] ?? null) ? $variant['selected_options'] : [];
            if ($attributes === []) {
                $label = trim((string) ($variant['label'] ?? 'Option'));
                $value = trim((string) ($variant['value'] ?? ''));
                if ($value === '') {
                    continue;
                }
                $attributes = [$label !== '' ? $label : 'Option' => $value];
            }

            $varSupplierPrice = isset($variant['price']) && is_numeric($variant['price']) && (float) $variant['price'] > 0
                ? (float) $variant['price']
                : $baseSupplierPrice;

            $ngnPrice = null;
            if ($varSupplierPrice > 0 && $baseSupplierPrice > 0 && abs($varSupplierPrice - $baseSupplierPrice) >= 0.01) {
                $priceKey = number_format($varSupplierPrice, 4, '.', '');
                if (! array_key_exists($priceKey, $quoteBySupplierPrice)) {
                    try {
                        $varQuote = $this->pricing->prepareQuote(
                            array_merge($supplierProduct, ['price' => $varSupplierPrice]),
                            [
                                'name' => $product->name,
                                'description' => $product->description,
                                'category_id' => $product->category_id,
                                'subcategory_id' => $product->subcategory_id,
                            ]
                        );
                        $quoteBySupplierPrice[$priceKey] = isset($varQuote['proposed_price_ngn']) && (float) $varQuote['proposed_price_ngn'] > 0
                            ? round((float) $varQuote['proposed_price_ngn'], 2)
                            : null;
                    } catch (Throwable) {
                        $quoteBySupplierPrice[$priceKey] = null;
                    }
                }
                $ngnPrice = $quoteBySupplierPrice[$priceKey];
            }

            if ($ngnPrice === null && isset($variant['proposed_price_ngn']) && is_numeric($variant['proposed_price_ngn']) && (float) $variant['proposed_price_ngn'] > 0) {
                $ngnPrice = round((float) $variant['proposed_price_ngn'], 2);
            }

            if ($ngnPrice === null && $varSupplierPrice > 0 && $baseSupplierPrice > 0 && $baseNgnPrice > 0) {
                $ngnPrice = abs($varSupplierPrice - $baseSupplierPrice) < 0.01
                    ? round($baseNgnPrice, 2)
                    : round(($varSupplierPrice / $baseSupplierPrice) * $baseNgnPrice, 2);
            }

            if ($ngnPrice === null || $ngnPrice <= 0) {
                $ngnPrice = $baseNgnPrice > 0 ? round($baseNgnPrice, 2) : round((float) $product->price, 2);
            }

            $rawSku = trim((string) ($variant['sku'] ?? $variant['id'] ?? ''));
            $baseSku = $this->uniqueSku(
                $rawSku !== '' ? $rawSku : "{$supplier}-{$externalProductId}-V".($index + 1),
                $supplier,
                $externalProductId
            );
            $variantSku = $baseSku;
            $suffix = 2;
            while (in_array($variantSku, $usedSkus, true) || ProductVariant::query()->where('sku', $variantSku)->exists()) {
                $variantSku = Str::limit($baseSku, 90, '').'-'.$suffix++;
            }
            $usedSkus[] = $variantSku;

            $available = array_key_exists('available', $variant) ? (bool) $variant['available'] : true;
            $stock = isset($variant['stock']) && is_numeric($variant['stock'])
                ? max(0, (int) $variant['stock'])
                : ($available ? 10 : 0);

            $variantImages = [];
            if (is_array($variant['images'] ?? null)) {
                foreach (array_slice($variant['images'], 0, 5) as $imgPos => $rawImg) {
                    $imgUrl = is_string($rawImg) ? $this->secureUrl($rawImg) : (is_array($rawImg) ? $this->secureUrl($rawImg['url'] ?? null) : null);
                    if (! $imgUrl) {
                        continue;
                    }
                    if (! isset($storedImageCache[$imgUrl])) {
                        $storedImageCache[$imgUrl] = $this->downloadAndStoreImage($imgUrl, $product->id, 100 + ($index * 10) + $imgPos);
                    }
                    if (! in_array($storedImageCache[$imgUrl], $variantImages, true)) {
                        $variantImages[] = $storedImageCache[$imgUrl];
                    }
                }
            }

            $createdVariant = ProductVariant::create([
                'product_id' => $product->id,
                'sku' => $variantSku,
                'attributes' => $attributes,
                'images' => $variantImages !== [] ? $variantImages : null,
                'price' => $ngnPrice,
                'stock' => $stock,
                'status' => $available ? 'active' : 'disabled',
            ]);

            $syncedVariants[] = array_merge($variant, [
                'variant_id' => $createdVariant->id,
                'sku' => $variantSku,
                'selected_options' => $attributes,
                'price' => $varSupplierPrice > 0 ? $varSupplierPrice : null,
                'proposed_price_ngn' => $ngnPrice,
                'available' => $available,
                'stock' => $stock,
                'images' => $variantImages !== [] ? $variantImages : ($variant['images'] ?? []),
            ]);
        }

        return $syncedVariants;
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
