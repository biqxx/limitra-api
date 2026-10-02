<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\ProductResource;
use App\Models\Product\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends BaseController
{
    private const LIST_RELATIONS = [
        'category.image',
        'subcategory.image',
        'productImages',
    ];

    private const DETAIL_RELATIONS = [
        'category.image',
        'subcategory.image',
        'creator',
        'productImages',
        'variants',
        'specifications',
        'sources',
    ];

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['sometimes', 'string', 'max:255'],
            'status' => ['sometimes', 'in:draft,active,archived'],
            'category_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'subcategory_id' => ['sometimes', 'integer', 'exists:categories,id'],
            'brand' => ['sometimes', 'string', 'max:100'],
            'source' => ['sometimes', 'in:amazon,ebay'],
            'stock_status' => ['sometimes', 'in:in_stock,out_of_stock,low_stock'],
            'is_featured' => ['sometimes', 'boolean'],
            'is_bestseller' => ['sometimes', 'boolean'],
            'trashed' => ['sometimes', 'in:without,with,only'],
            'sort_by' => ['sometimes', 'in:id,name,price,stock,status,created_at,updated_at'],
            'sort_dir' => ['sometimes', 'in:asc,desc'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $search = isset($data['q']) ? '%'.$data['q'].'%' : null;
        $products = Product::query()
            ->with(self::LIST_RELATIONS)
            ->withCount(['variants', 'sources'])
            ->when(($data['trashed'] ?? 'without') === 'with', fn ($query) => $query->withTrashed())
            ->when(($data['trashed'] ?? 'without') === 'only', fn ($query) => $query->onlyTrashed())
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->where('name', 'like', $search)
                    ->orWhere('slug', 'like', $search)
                    ->orWhere('brand', 'like', $search)
                    ->orWhere('sku', 'like', $search);
            }))
            ->when($data['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($data['category_id'] ?? null, fn ($query, $categoryId) => $query->where('category_id', $categoryId))
            ->when($data['subcategory_id'] ?? null, fn ($query, $subcategoryId) => $query->where('subcategory_id', $subcategoryId))
            ->when($data['brand'] ?? null, fn ($query, $brand) => $query->where('brand', $brand))
            ->when($data['source'] ?? null, fn ($query, $source) => $query->whereHas('sources', fn ($sources) => $sources->where('supplier', $source)))
            ->when(($data['stock_status'] ?? null) === 'in_stock', fn ($query) => $query->where('stock', '>', 0))
            ->when(($data['stock_status'] ?? null) === 'out_of_stock', fn ($query) => $query->where('stock', 0))
            ->when(($data['stock_status'] ?? null) === 'low_stock', fn ($query) => $query->where('stock', '>', 0)->whereColumn('stock', '<=', 'low_stock_threshold'))
            ->when(array_key_exists('is_featured', $data), fn ($query) => $query->where('is_featured', $data['is_featured']))
            ->when(array_key_exists('is_bestseller', $data), fn ($query) => $query->where('is_bestseller', $data['is_bestseller']))
            ->orderBy($data['sort_by'] ?? 'created_at', $data['sort_dir'] ?? 'desc')
            ->paginate($data['per_page'] ?? 20)
            ->withQueryString();

        return $this->success([
            'items' => ProductResource::collection($products->getCollection())->resolve($request),
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'from' => $products->firstItem(),
                'to' => $products->lastItem(),
            ],
        ]);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        return $this->success(
            (new ProductResource($product->load(self::DETAIL_RELATIONS)))->resolve($request)
        );
    }

    public function restore(Request $request, Product $product): JsonResponse
    {
        if (! $product->trashed()) {
            return $this->error('Product is not deleted.', 409);
        }

        $product->restore();

        return $this->success(
            (new ProductResource($product->load(self::DETAIL_RELATIONS)))->resolve($request),
            'Product restored.',
        );
    }
}
