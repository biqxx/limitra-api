<?php

namespace App\Services\Sourcing;

use App\Models\Product\Category;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use JsonException;
use UnexpectedValueException;

class ProductContentEnhancer
{
    public function __construct(private readonly SupplierServiceClient $client) {}

    /**
     * @return array{supplier_product: array<string, mixed>, enhanced: array<string, mixed>}
     */
    public function enhance(string $supplier, string $externalProductId): array
    {
        if (! filled(config('ai.openrouter.api_key'))) {
            throw new UnexpectedValueException('AI product enhancement is not configured.');
        }

        $supplierProduct = $this->client->import($supplier, $externalProductId);
        $categories = $this->categories();
        $prompt = $this->prompt($supplierProduct, $categories);
        $lastError = null;

        foreach (config('ai.openrouter.product_content_models', []) as $model) {
            try {
                $response = Http::baseUrl(rtrim((string) config('ai.openrouter.base_url'), '/'))
                    ->withToken((string) config('ai.openrouter.api_key'))
                    ->acceptJson()
                    ->withHeaders(array_filter([
                        'HTTP-Referer' => config('ai.openrouter.site_url'),
                        'X-Title' => config('ai.openrouter.app_name'),
                    ]))
                    ->connectTimeout((int) config('ai.openrouter.connect_timeout'))
                    ->timeout((int) config('ai.openrouter.timeout'))
                    ->post('chat/completions', [
                        'model' => $model,
                        'max_tokens' => max(1024, (int) config('ai.openrouter.max_tokens')),
                        'temperature' => 0.2,
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'You are a careful ecommerce catalog editor. Return only valid JSON and never invent product facts.',
                            ],
                            ['role' => 'user', 'content' => $prompt],
                        ],
                    ]);

                if (! $response->successful()) {
                    $lastError = 'The AI provider rejected the request.';

                    continue;
                }

                $parsed = $this->parseJson(data_get($response->json(), 'choices.0.message.content'));

                return [
                    'supplier_product' => $this->supplierPreview($supplier, $externalProductId, $supplierProduct),
                    'enhanced' => $this->clean($parsed, $supplierProduct, $categories),
                ];
            } catch (ConnectionException) {
                $lastError = 'The AI provider is unavailable.';
            } catch (JsonException) {
                $lastError = 'The AI provider returned invalid product content.';
            }
        }

        throw new UnexpectedValueException($lastError ?? 'AI product enhancement is unavailable.');
    }

    /** @return array<int, array{id: int, name: string, subcategories: array<int, array{id: int, name: string}>}> */
    private function categories(): array
    {
        return Category::query()
            ->whereNull('parent_id')
            ->where('active', true)
            ->with(['subcategories' => fn ($query) => $query
                ->where('active', true)
                ->orderBy('name')
                ->select(['id', 'parent_id', 'name'])])
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'subcategories' => $category->subcategories->map(fn (Category $subcategory): array => [
                    'id' => $subcategory->id,
                    'name' => $subcategory->name,
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $supplierProduct
     * @param  array<int, array<string, mixed>>  $categories
     */
    private function prompt(array $supplierProduct, array $categories): string
    {
        $sourceJson = mb_substr(json_encode($supplierProduct, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 0, 18000);
        $categoryJson = json_encode($categories, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return <<<PROMPT
Rewrite this supplier product for the Limitra Nigeria catalog.

Supplier product JSON:
{$sourceJson}

Available categories JSON:
{$categoryJson}

Return exactly one JSON object with this shape:
{"name":string,"brand":string,"description":string,"category_id":integer|null,"subcategory_id":integer|null,"specifications":[{"name":string,"value":string}]}

Rules:
- Use only facts supported by the supplier product. Never invent specifications, compatibility, materials, dimensions, warranties, or included items.
- Preserve the supplier's real brand when present.
- Write a clear, natural product name without keyword stuffing.
- Write a polished, shopper-friendly description of 2 to 4 sentences.
- Include at most 8 useful specifications and exclude shipping, seller, ranking, and administrative details.
- Choose category_id and subcategory_id only from the supplied category JSON. A subcategory must belong to the chosen category.
- Return JSON only, without markdown or commentary.
PROMPT;
    }

    /** @return array<string, mixed> */
    private function parseJson(mixed $content): array
    {
        if (! is_string($content) || trim($content) === '') {
            throw new JsonException('Empty AI response.');
        }

        $json = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content));
        $decoded = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new JsonException('Invalid AI response.');
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $parsed
     * @param  array<string, mixed>  $supplierProduct
     * @param  array<int, array<string, mixed>>  $categories
     * @return array<string, mixed>
     */
    private function clean(array $parsed, array $supplierProduct, array $categories): array
    {
        $categoryId = isset($parsed['category_id']) ? (int) $parsed['category_id'] : null;
        $category = collect($categories)->firstWhere('id', $categoryId);
        $subcategoryId = isset($parsed['subcategory_id']) ? (int) $parsed['subcategory_id'] : null;

        if (! $category || ! collect($category['subcategories'])->contains('id', $subcategoryId)) {
            $subcategoryId = null;
        }

        if (! $category) {
            $categoryId = null;
        }

        $specifications = collect(is_array($parsed['specifications'] ?? null) ? $parsed['specifications'] : [])
            ->map(fn (mixed $specification): array => [
                'name' => trim((string) data_get($specification, 'name')),
                'value' => trim((string) data_get($specification, 'value')),
            ])
            ->filter(fn (array $specification): bool => $specification['name'] !== '' && $specification['value'] !== '')
            ->take(8)
            ->values()
            ->all();

        return [
            'name' => trim((string) ($parsed['name'] ?? '')) ?: trim((string) ($supplierProduct['title'] ?? '')),
            'brand' => trim((string) ($parsed['brand'] ?? '')) ?: trim((string) ($supplierProduct['brand'] ?? '')),
            'description' => trim((string) ($parsed['description'] ?? '')) ?: trim((string) ($supplierProduct['description'] ?? '')),
            'category_id' => $categoryId,
            'subcategory_id' => $subcategoryId,
            'specifications' => $specifications,
        ];
    }

    /**
     * @param  array<string, mixed>  $supplierProduct
     * @return array<string, mixed>
     */
    private function supplierPreview(string $supplier, string $externalProductId, array $supplierProduct): array
    {
        return [
            'source' => $supplier,
            'external_product_id' => $externalProductId,
            'title' => $supplierProduct['title'] ?? null,
            'brand' => $supplierProduct['brand'] ?? null,
            'description' => $supplierProduct['description'] ?? null,
            'image_url' => $supplierProduct['image_url'] ?? null,
            'product_url' => $supplierProduct['product_url'] ?? null,
            'price' => $supplierProduct['price'] ?? null,
            'currency' => $supplierProduct['currency'] ?? null,
            'final_price_ngn' => $supplierProduct['final_price_ngn'] ?? null,
            'available' => $supplierProduct['available'] ?? null,
        ];
    }
}
