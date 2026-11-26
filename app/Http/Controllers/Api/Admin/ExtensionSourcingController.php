<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exceptions\SupplierProductDataException;
use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\ProductSourceResource;
use App\Models\Product\Category;
use App\Services\Sourcing\ProductContentEnhancer;
use App\Services\Sourcing\ProductPricingService;
use App\Services\Sourcing\SupplierProductImportService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;
use Throwable;
use UnexpectedValueException;

class ExtensionSourcingController extends BaseController
{
    #[OA\Post(
        path: '/admin/sourcing/extension-review',
        tags: ['Admin Sourcing'],
        summary: 'AI-enhance and calculate NGN pricing quote for a browser-extension product',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 200, description: 'Reviewed product with AI metadata and NGN quote', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function review(
        Request $request,
        ProductContentEnhancer $enhancer,
        ProductPricingService $pricing,
    ): JsonResponse {
        $data = $request->validate([
            'source' => ['required', 'string', 'max:50'],
            'external_product_id' => ['required', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:500'],
            'brand' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string'],
            'bullet_points' => ['nullable', 'array'],
            'bullet_points.*' => ['string'],
            'specifications' => ['nullable', 'array'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'shipping_price' => ['nullable', 'numeric', 'min:0'],
            'weight_lb' => ['nullable', 'numeric', 'min:0'],
            'us_delivery_days_min' => ['nullable', 'integer', 'min:0'],
            'us_delivery_days_max' => ['nullable', 'integer', 'min:0'],
            'available' => ['nullable', 'boolean'],
            'image_url' => ['nullable', 'string'],
            'images' => ['nullable', 'array'],
            'images.*' => ['string'],
            'product_url' => ['nullable', 'string'],
            'category' => ['nullable', 'string', 'max:255'],
            'category_path' => ['nullable', 'array'],
            'variants' => ['nullable', 'array'],
            'categories' => ['nullable', 'array'],
        ]);

        $supplierProduct = $this->normalizeSupplierPayload($data);
        $aiWarning = null;
        $enhanced = [
            'name' => $supplierProduct['title'],
            'brand' => $supplierProduct['brand'] ?? '',
            'description' => $supplierProduct['description'] ?? '',
            'category_id' => null,
            'subcategory_id' => null,
            'specifications' => $this->normalizeSpecs($data['specifications'] ?? []),
        ];

        try {
            $aiResult = $enhancer->enhancePayload(
                $data['source'],
                $data['external_product_id'],
                $supplierProduct
            );
            $enhanced = array_merge($enhanced, $aiResult['enhanced'] ?? []);
        } catch (Throwable $e) {
            $aiWarning = $e->getMessage();
        }

        if (empty($enhanced['category_id'])) {
            [$matchedCatId, $matchedSubId] = $this->bestFitCategory(
                $supplierProduct['title'].' '.implode(' ', $supplierProduct['category_path'] ?? []).' '.($supplierProduct['category'] ?? ''),
                $data['categories'] ?? null
            );
            if ($matchedCatId) {
                $enhanced['category_id'] = $matchedCatId;
                $enhanced['subcategory_id'] = $matchedSubId;
            }
        }

        $quote = null;
        $pricingWarning = null;
        $variantsWithNgn = $supplierProduct['variants'];

        if (is_numeric($supplierProduct['price']) && (float) $supplierProduct['price'] > 0) {
            try {
                $quote = $pricing->prepareQuote($supplierProduct, [
                    'name' => $enhanced['name'],
                    'description' => $enhanced['description'],
                    'category_id' => $enhanced['category_id'],
                    'subcategory_id' => $enhanced['subcategory_id'],
                ]);

                $baseSupplierPrice = (float) $supplierProduct['price'];
                $baseNgn = (float) ($quote['proposed_price_ngn'] ?? 0);

                $variantsWithNgn = array_map(function (array $variant) use ($supplierProduct, $pricing, $enhanced, $baseSupplierPrice, $baseNgn): array {
                    $varPrice = isset($variant['price']) && is_numeric($variant['price']) ? (float) $variant['price'] : null;
                    if ($varPrice !== null && $varPrice > 0) {
                        if (abs($varPrice - $baseSupplierPrice) < 0.01 && $baseNgn > 0) {
                            $variant['proposed_price_ngn'] = $baseNgn;
                        } else {
                            try {
                                $varQuote = $pricing->prepareQuote(
                                    array_merge($supplierProduct, ['price' => $varPrice]),
                                    ['name' => $enhanced['name'], 'category_id' => $enhanced['category_id']]
                                );
                                $variant['proposed_price_ngn'] = $varQuote['proposed_price_ngn'] ?? null;
                            } catch (Throwable) {
                                $variant['proposed_price_ngn'] = ($baseSupplierPrice > 0 && $baseNgn > 0)
                                    ? round(($varPrice / $baseSupplierPrice) * $baseNgn, 2)
                                    : null;
                            }
                        }
                    } else {
                        $variant['proposed_price_ngn'] = $baseNgn > 0 ? $baseNgn : null;
                    }

                    return $variant;
                }, $supplierProduct['variants']);
            } catch (Throwable $e) {
                $pricingWarning = $e->getMessage();
            }
        } else {
            $pricingWarning = 'Supplier price is missing; enter or verify a valid price before publishing.';
        }

        $seoMeta = [
            'title' => mb_substr($enhanced['name'].($enhanced['brand'] ? ' — '.$enhanced['brand'] : ''), 0, 70),
            'description' => mb_substr(preg_replace('/\s+/', ' ', strip_tags((string) $enhanced['description'])), 0, 158),
            'keywords' => array_values(array_filter([
                $enhanced['name'],
                $enhanced['brand'],
                $data['source'],
                'Limitra',
            ])),
        ];

        return $this->success([
            'enhanced' => $enhanced,
            'seo_meta' => $seoMeta,
            'quote' => $quote,
            'variants' => $variantsWithNgn,
            'warnings' => array_values(array_filter([$aiWarning, $pricingWarning])),
        ], 'Extension product reviewed.');
    }

    #[OA\Post(
        path: '/admin/sourcing/extension-import',
        tags: ['Admin Sourcing'],
        summary: 'Import and optionally publish a browser-extension product with variants',
        security: [['bearerAuth' => []]],
        responses: [new OA\Response(response: 201, description: 'Product imported', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse'))]
    )]
    public function import(
        Request $request,
        SupplierProductImportService $importer,
    ): JsonResponse {
        $validated = $request->validate([
            'source' => ['required', 'string', 'max:50'],
            'external_product_id' => ['required', 'string', 'max:120'],
            'category_id' => ['required', Rule::exists('categories', 'id')->whereNull('parent_id')],
            'subcategory_id' => ['nullable', Rule::exists('categories', 'id')->where('parent_id', $request->input('category_id'))],
            'name' => ['required', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:100'],
            'sku' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string'],
            'seo_meta' => ['nullable', 'array'],
            'publish' => ['nullable', 'boolean'],
            'stock' => ['nullable', 'integer', 'min:0'],
            'specifications' => ['nullable', 'array'],
            'specifications.*.name' => ['required', 'string', 'max:100'],
            'specifications.*.value' => ['required', 'string', 'max:500'],
            'price' => ['required', 'numeric', 'gt:0'],
            'original_price' => ['nullable', 'numeric', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'shipping_price' => ['nullable', 'numeric', 'min:0'],
            'weight_lb' => ['nullable', 'numeric', 'min:0'],
            'us_delivery_days_min' => ['nullable', 'integer', 'min:0'],
            'us_delivery_days_max' => ['nullable', 'integer', 'min:0'],
            'available' => ['nullable', 'boolean'],
            'image_url' => ['nullable', 'string'],
            'images' => ['nullable', 'array'],
            'images.*' => ['string'],
            'product_url' => ['nullable', 'string'],
            'variants' => ['nullable', 'array'],
            'variants.*.id' => ['nullable', 'string'],
            'variants.*.sku' => ['nullable', 'string', 'max:100'],
            'variants.*.label' => ['nullable', 'string'],
            'variants.*.value' => ['nullable', 'string'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.proposed_price_ngn' => ['nullable', 'numeric', 'min:0'],
            'variants.*.available' => ['nullable', 'boolean'],
            'variants.*.stock' => ['nullable', 'integer', 'min:0'],
            'variants.*.selected_options' => ['nullable', 'array'],
            'variants.*.images' => ['nullable', 'array'],
        ]);

        $supplierProduct = $this->normalizeSupplierPayload($validated);

        try {
            $source = $importer->importResolved($validated, $request->user(), $supplierProduct);
        } catch (SupplierProductDataException $exception) {
            return $this->error(
                $exception->getMessage(),
                422,
                ['missing_fields' => $exception->missingFields],
                $exception->errorCode
            );
        } catch (DomainException $exception) {
            return $this->error($exception->getMessage(), 409);
        } catch (UnexpectedValueException $exception) {
            return $this->error($exception->getMessage(), 422);
        }

        $message = ! empty($validated['publish'])
            ? 'Product reviewed and published officially to Limitra.'
            : 'Product imported as draft.';

        return $this->success(
            (new ProductSourceResource($source))->resolve($request),
            $message,
            201
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalizeSupplierPayload(array $data): array
    {
        $images = array_values(array_filter(array_map('strval', $data['images'] ?? [])));
        $primaryImage = ! empty($data['image_url']) ? (string) $data['image_url'] : ($images[0] ?? '');
        $currency = strtoupper(trim((string) ($data['currency'] ?? 'USD')));

        return [
            'supplier' => $data['source'],
            'supplier_id' => $data['external_product_id'],
            'title' => $data['title'] ?? $data['name'] ?? '',
            'brand' => $data['brand'] ?? null,
            'description' => $data['description'] ?? '',
            'bullet_points' => $data['bullet_points'] ?? [],
            'specifications' => $data['specifications'] ?? [],
            'price' => isset($data['price']) ? (float) $data['price'] : null,
            'original_price' => isset($data['original_price']) ? (float) $data['original_price'] : null,
            'currency' => $currency,
            'shipping_price' => isset($data['shipping_price']) ? (float) $data['shipping_price'] : null,
            'weight_lb' => isset($data['weight_lb']) ? (float) $data['weight_lb'] : null,
            'us_delivery_days_min' => $data['us_delivery_days_min'] ?? null,
            'us_delivery_days_max' => $data['us_delivery_days_max'] ?? null,
            'available' => (bool) ($data['available'] ?? true),
            'image_url' => $primaryImage,
            'images' => $images,
            'product_url' => $data['product_url'] ?? '',
            'category' => $data['category'] ?? null,
            'category_path' => $data['category_path'] ?? [],
            'variants' => array_values(array_map(function ($v): array {
                $v = is_array($v) ? $v : [];
                $selected = is_array($v['selected_options'] ?? null) ? $v['selected_options'] : [];

                return [
                    'id' => isset($v['id']) ? (string) $v['id'] : null,
                    'sku' => isset($v['sku']) ? (string) $v['sku'] : null,
                    'label' => (string) ($v['label'] ?? implode(' / ', array_keys($selected)) ?: 'Option'),
                    'value' => (string) ($v['value'] ?? implode(' / ', array_values($selected)) ?: 'Default'),
                    'price' => isset($v['price']) && is_numeric($v['price']) ? (float) $v['price'] : null,
                    'proposed_price_ngn' => isset($v['proposed_price_ngn']) && is_numeric($v['proposed_price_ngn']) ? (float) $v['proposed_price_ngn'] : null,
                    'available' => array_key_exists('available', $v) ? (bool) $v['available'] : true,
                    'stock' => isset($v['stock']) && is_numeric($v['stock']) ? (int) $v['stock'] : null,
                    'selected_options' => $selected,
                    'images' => is_array($v['images'] ?? null) ? $v['images'] : [],
                ];
            }, $data['variants'] ?? [])),
            'data_quality' => [
                'price_verified' => isset($data['price']) && is_numeric($data['price']) && (float) $data['price'] > 0,
                'currency_verified' => preg_match('/^[A-Z]{3}$/', $currency) === 1,
                'images_verified' => count($images) > 0 || $primaryImage !== '',
            ],
        ];
    }

    /** @return array<int, array{name: string, value: string}> */
    private function normalizeSpecs(mixed $specs): array
    {
        if (! is_array($specs)) {
            return [];
        }

        $out = [];
        foreach ($specs as $key => $val) {
            if (is_array($val) && isset($val['name'], $val['value'])) {
                $out[] = ['name' => (string) $val['name'], 'value' => (string) $val['value']];
            } elseif (is_string($key) && (is_string($val) || is_numeric($val))) {
                $out[] = ['name' => $key, 'value' => (string) $val];
            }
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }

    /**
     * Deterministic fallback when AI did not select a category.
     *
     * @param  array<int, mixed>|null  $providedCategories
     * @return array{0: int|null, 1: int|null}
     */
    private function bestFitCategory(string $haystack, ?array $providedCategories = null): array
    {
        $text = mb_strtolower($haystack);
        $categories = is_array($providedCategories) && $providedCategories !== []
            ? $providedCategories
            : Category::query()
                ->whereNull('parent_id')
                ->where('active', true)
                ->with(['subcategories' => fn ($q) => $q->where('active', true)->select(['id', 'parent_id', 'name'])])
                ->get(['id', 'name'])
                ->toArray();

        $bestCatId = null;
        $bestSubId = null;
        $bestScore = 0;

        foreach ($categories as $cat) {
            $catId = isset($cat['id']) ? (int) $cat['id'] : null;
            $catName = mb_strtolower((string) ($cat['name'] ?? ''));
            if (! $catId || $catName === '') {
                continue;
            }

            $catScore = $this->keywordScore($text, $catName);
            $subs = is_array($cat['subcategories'] ?? null) ? $cat['subcategories'] : [];

            foreach ($subs as $sub) {
                $subId = isset($sub['id']) ? (int) $sub['id'] : null;
                $subName = mb_strtolower((string) ($sub['name'] ?? ''));
                if (! $subId || $subName === '') {
                    continue;
                }
                $score = $catScore + ($this->keywordScore($text, $subName) * 2);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestCatId = $catId;
                    $bestSubId = $subId;
                }
            }

            if ($catScore > $bestScore) {
                $bestScore = $catScore;
                $bestCatId = $catId;
                $bestSubId = null;
            }
        }

        return [$bestCatId, $bestSubId];
    }

    private function keywordScore(string $text, string $label): int
    {
        $words = preg_split('/[\s,&\/-]+/', $label, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $score = 0;
        foreach ($words as $w) {
            if (mb_strlen($w) >= 3 && str_contains($text, $w)) {
                $score += 1;
            }
        }

        return $score;
    }
}

