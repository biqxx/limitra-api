<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\ProductResource;
use App\Jobs\RecordAnalyticsEvent;
use App\Models\Product\Product;
use App\Models\Product\ProductVariant;
use App\Services\Analytics\AnalyticsTrackingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class ProductController extends BaseController
{
    #[OA\Get(
        path: '/products',
        tags: ['Products'],
        summary: 'List products (public)',
        parameters: [
            new OA\Parameter(name: 'category_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'subcategory_id', in: 'query', required: false, schema: new OA\Schema(type: 'integer')),
            new OA\Parameter(name: 'search', in: 'query', required: false, schema: new OA\Schema(type: 'string')),
            new OA\Parameter(name: 'price_min', in: 'query', required: false, schema: new OA\Schema(type: 'number', format: 'float')),
            new OA\Parameter(name: 'price_max', in: 'query', required: false, schema: new OA\Schema(type: 'number', format: 'float')),
            new OA\Parameter(name: 'in_stock', in: 'query', required: false, schema: new OA\Schema(type: 'boolean')),
            new OA\Parameter(name: 'sort_by', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['id', 'price', 'name'], default: 'id')),
            new OA\Parameter(name: 'sort_dir', in: 'query', required: false, schema: new OA\Schema(type: 'string', enum: ['asc', 'desc'], default: 'desc')),
            new OA\Parameter(name: 'per_page', in: 'query', required: false, schema: new OA\Schema(type: 'integer', default: 15)),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Paginated product list', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'category_slug' => ['nullable', 'string', 'exists:categories,slug'],
            'subcategory_id' => ['nullable', 'integer', 'exists:categories,id'],
            'subcategory_slug' => ['nullable', 'string', 'exists:categories,slug'],
            'brand' => ['nullable'],
            'brand.*' => ['string', 'max:100'],
            'price_min' => ['nullable', 'numeric', 'min:0'],
            'price_max' => ['nullable', 'numeric', 'min:0'],
            'min_price' => ['nullable', 'numeric', 'min:0'],
            'max_price' => ['nullable', 'numeric', 'min:0'],
            'min_rating' => ['nullable', 'numeric', 'between:0,5'],
            'in_stock' => ['nullable', 'boolean'],
            'on_sale' => ['nullable', 'boolean'],
            'featured' => ['nullable', 'boolean'],
            'bestseller' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:featured,newest,popular,rating,price,name,price-low,price-high,name-az'],
            'sort_by' => ['nullable', 'in:id,price,name,created_at,average_rating,review_count'],
            'sort_dir' => ['nullable', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'between:1,100'],
        ]);

        [$sortBy, $sortDir] = $this->resolveSort($request);
        $search = $request->input('q', $request->input('search'));
        $minPrice = $request->input('min_price', $request->input('price_min'));
        $maxPrice = $request->input('max_price', $request->input('price_max'));
        $brands = $request->input('brand', []);
        $brands = is_array($brands) ? $brands : array_filter(array_map('trim', explode(',', $brands)));

        $products = Product::with('category.image', 'subcategory.image', 'productImages')
            ->where('status', 'active')
            ->when($request->filled('subcategory_id'), fn ($q) => $q->where('subcategory_id', $request->integer('subcategory_id')))
            ->when($request->filled('subcategory_slug'), fn ($q) => $q->whereHas('subcategory', fn ($subquery) => $subquery->where('slug', $request->input('subcategory_slug'))))
            ->when($request->filled('category_id'), fn ($q) => $q->where('category_id', $request->integer('category_id')))
            ->when($request->filled('category_slug'), fn ($q) => $q->whereHas('category', fn ($categoryQuery) => $categoryQuery->where('slug', $request->input('category_slug'))))
            ->when($search, function ($query) use ($search) {
                $query->where(function ($searchQuery) use ($search) {
                    $searchQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('brand', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%")
                        ->orWhere('sku', 'like', "%{$search}%");
                });
            })
            ->when($brands, fn ($q) => $q->whereIn('brand', $brands))
            ->when($minPrice !== null, fn ($q) => $q->where('price', '>=', $minPrice))
            ->when($maxPrice !== null, fn ($q) => $q->where('price', '<=', $maxPrice))
            ->when($request->filled('min_rating'), fn ($q) => $q->where('average_rating', '>=', $request->input('min_rating')))
            ->when($request->boolean('in_stock'), fn ($q) => $q->where('stock', '>', 0))
            ->when($request->boolean('on_sale'), fn ($q) => $q->whereNotNull('compare_at_price')->whereColumn('compare_at_price', '>', 'price'))
            ->when($request->boolean('featured'), fn ($q) => $q->where('is_featured', true))
            ->when($request->boolean('bestseller'), fn ($q) => $q->where('is_bestseller', true))
            ->orderBy($sortBy, $sortDir)
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return $this->success([
            'items' => ProductResource::collection($products->getCollection()),
            'pagination' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
                'from' => $products->firstItem(),
                'to' => $products->lastItem(),
            ],
        ]);
    }

    #[OA\Post(
        path: '/products',
        tags: ['Products'],
        summary: 'Create product (admin)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['category_id', 'name', 'price', 'stock'],
                properties: [
                    new OA\Property(property: 'category_id', type: 'integer'),
                    new OA\Property(property: 'subcategory_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'price', type: 'number', format: 'float', minimum: 0),
                    new OA\Property(property: 'stock', type: 'integer', minimum: 0),
                    new OA\Property(property: 'images', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Product created', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
            new OA\Response(response: 422, description: 'Validation error', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Product::class);

        $data = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'subcategory_id' => ['nullable', Rule::exists('categories', 'id')->where('parent_id', $request->category_id)],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:products,slug'],
            'brand' => ['nullable', 'string', 'max:100'],
            'sku' => ['nullable', 'string', 'max:100', 'unique:products,sku'],
            'description' => ['nullable', 'string'],
            'price' => ['required', 'numeric', 'min:0'],
            'compare_at_price' => ['nullable', 'numeric', 'gt:price'],
            'currency' => ['nullable', 'string', 'size:3'],
            'stock' => ['required', 'integer', 'min:0'],
            'low_stock_threshold' => ['nullable', 'integer', 'min:0'],
            'status' => ['nullable', 'in:draft,active,archived'],
            'is_featured' => ['nullable', 'boolean'],
            'is_bestseller' => ['nullable', 'boolean'],
            'images' => ['nullable', 'array'],
            'images.*' => ['string'],
            'seo_meta' => ['nullable', 'array'],
            'variants' => ['nullable', 'array'],
            'variants.*.sku' => ['required', 'string', 'max:100', 'distinct', 'unique:product_variants,sku'],
            'variants.*.attributes' => ['required', 'array', 'min:1'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.stock' => ['required', 'integer', 'min:0'],
            'variants.*.status' => ['nullable', 'in:active,disabled'],
            'specifications' => ['nullable', 'array'],
            'specifications.*.group' => ['nullable', 'string', 'max:100'],
            'specifications.*.name' => ['required', 'string', 'max:100'],
            'specifications.*.value' => ['required', 'string'],
            'specifications.*.position' => ['nullable', 'integer', 'min:0'],
        ]);

        $variants = $data['variants'] ?? [];
        $specifications = $data['specifications'] ?? [];
        unset($data['variants'], $data['specifications']);

        $data['slug'] = $data['slug'] ?? $this->uniqueSlug($data['name']);
        $data['currency'] = strtoupper($data['currency'] ?? 'NGN');
        $data['created_by'] = auth('api')->id();

        $product = DB::transaction(function () use ($data, $variants, $specifications) {
            $product = Product::create($data);
            $product->variants()->createMany($variants);
            $product->specifications()->createMany(array_map(fn ($specification) => [
                'group' => $specification['group'] ?? null,
                'name' => $specification['name'],
                'value' => $specification['value'],
                'sort_order' => $specification['position'] ?? 0,
            ], $specifications));

            return $product;
        });

        return $this->success(
            new ProductResource($product->load('category.image', 'subcategory.image', 'creator', 'productImages', 'variants', 'specifications')),
            'Product created.',
            201
        );
    }

    #[OA\Get(
        path: '/products/{product}',
        tags: ['Products'],
        summary: 'Get product (public)',
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Product details', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(Request $request, Product $product): JsonResponse
    {
        $sessionId = $request->attributes->get('analytics_session_id')
            ?? AnalyticsTrackingService::resolveSessionId($request);

        dispatch(RecordAnalyticsEvent::productView(
            $request,
            $sessionId,
            auth('api')->id(),
            $product->id,
        ));

        abort_unless($product->status === 'active' || auth('api')->user()?->isAdmin(), 404);

        return $this->success(new ProductResource($product->load('category.image', 'subcategory.image', 'creator', 'productImages', 'variants', 'specifications')));
    }

    #[OA\Patch(
        path: '/products/{product}',
        tags: ['Products'],
        summary: 'Update product (admin)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'category_id', type: 'integer'),
                    new OA\Property(property: 'subcategory_id', type: 'integer', nullable: true),
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                    new OA\Property(property: 'price', type: 'number', format: 'float'),
                    new OA\Property(property: 'stock', type: 'integer'),
                    new OA\Property(property: 'images', type: 'array', items: new OA\Items(type: 'string'), nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 200, description: 'Product updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(Request $request, Product $product): JsonResponse
    {
        $this->authorize('update', $product);

        // Use incoming category_id if provided, otherwise fall back to the product's current one.
        $effectiveCategoryId = $request->input('category_id', $product->category_id);

        $data = $request->validate([
            'category_id' => ['sometimes', 'required', 'exists:categories,id'],
            'subcategory_id' => ['nullable', Rule::exists('categories', 'id')->where('parent_id', $effectiveCategoryId)],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($product)],
            'brand' => ['nullable', 'string', 'max:100'],
            'sku' => ['nullable', 'string', 'max:100', Rule::unique('products', 'sku')->ignore($product)],
            'description' => ['nullable', 'string'],
            'price' => ['sometimes', 'required', 'numeric', 'min:0'],
            'compare_at_price' => [
                'nullable',
                'numeric',
                function (string $attribute, mixed $value, $fail) use ($request, $product): void {
                    $price = (float) $request->input('price', $product->price);

                    if ($value !== null && (float) $value <= $price) {
                        $fail('The compare at price must be greater than the price.');
                    }
                },
            ],
            'currency' => ['sometimes', 'required', 'string', 'size:3'],
            'stock' => ['sometimes', 'required', 'integer', 'min:0'],
            'low_stock_threshold' => ['sometimes', 'required', 'integer', 'min:0'],
            'status' => ['sometimes', 'required', 'in:draft,active,archived'],
            'is_featured' => ['sometimes', 'required', 'boolean'],
            'is_bestseller' => ['sometimes', 'required', 'boolean'],
            'images' => ['nullable', 'array'],
            'images.*' => ['string'],
            'seo_meta' => ['nullable', 'array'],
            'variants' => ['sometimes', 'array'],
            'variants.*.sku' => [
                'required',
                'string',
                'max:100',
                'distinct',
                function (string $attribute, mixed $value, $fail) use ($product): void {
                    if (ProductVariant::where('sku', $value)->where('product_id', '!=', $product->id)->exists()) {
                        $fail('The variant SKU has already been taken.');
                    }
                },
            ],
            'variants.*.attributes' => ['required', 'array', 'min:1'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.stock' => ['required', 'integer', 'min:0'],
            'variants.*.status' => ['nullable', 'in:active,disabled'],
            'specifications' => ['sometimes', 'array'],
            'specifications.*.group' => ['nullable', 'string', 'max:100'],
            'specifications.*.name' => ['required', 'string', 'max:100'],
            'specifications.*.value' => ['required', 'string'],
            'specifications.*.position' => ['nullable', 'integer', 'min:0'],
        ]);

        $variants = $data['variants'] ?? null;
        $specifications = $data['specifications'] ?? null;
        unset($data['variants'], $data['specifications']);

        if (isset($data['currency'])) {
            $data['currency'] = strtoupper($data['currency']);
        }

        $product->recordEditor(auth('api')->id());
        $data['updated_by'] = $product->updated_by;

        DB::transaction(function () use ($product, $data, $variants, $specifications): void {
            $product->update($data);

            if ($variants !== null) {
                $product->variants()->delete();
                $product->variants()->createMany($variants);
            }

            if ($specifications !== null) {
                $product->specifications()->delete();
                $product->specifications()->createMany(array_map(fn ($specification) => [
                    'group' => $specification['group'] ?? null,
                    'name' => $specification['name'],
                    'value' => $specification['value'],
                    'sort_order' => $specification['position'] ?? 0,
                ], $specifications));
            }
        });

        return $this->success(
            new ProductResource($product->load('category.image', 'subcategory.image', 'creator', 'productImages', 'variants', 'specifications')),
            'Product updated.'
        );
    }

    #[OA\Delete(
        path: '/products/{product}',
        tags: ['Products'],
        summary: 'Delete product (admin)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'product', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Product deleted', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function destroy(Product $product): JsonResponse
    {
        $this->authorize('delete', $product);

        $product->delete();

        return $this->success(message: 'Product deleted.');
    }

    private function resolveSort(Request $request): array
    {
        if ($request->filled('sort_by')) {
            return [$request->input('sort_by'), $request->input('sort_dir', 'desc')];
        }

        return match ($request->input('sort', 'featured')) {
            'newest' => ['created_at', 'desc'],
            'popular' => ['review_count', 'desc'],
            'rating' => ['average_rating', 'desc'],
            'price', 'price-low' => ['price', 'asc'],
            'price-high' => ['price', 'desc'],
            'name', 'name-az' => ['name', 'asc'],
            default => ['is_featured', 'desc'],
        };
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'product';
        $slug = $base;
        $suffix = 1;

        while (Product::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
