<?php

namespace App\Http\Controllers\Api;

use App\Http\Resources\CategoryResource;
use App\Models\Product\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use OpenApi\Attributes as OA;

class CategoryController extends BaseController
{
    /** Public. GET /categories */
    #[OA\Get(
        path: '/categories',
        tags: ['Categories'],
        summary: 'List top-level categories with subcategories (public)',
        responses: [
            new OA\Response(response: 200, description: 'Category list with subcategories and product counts', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
        ]
    )]
    public function index(): JsonResponse
    {
        $categories = Category::with([
            'image',
            'subcategories' => fn ($q) => $q->where('active', true)->with('image')->withCount(['subcategoryProducts as products_count'])->orderBy('sort_order')->orderBy('name'),
        ])
            ->withCount('products')
            ->whereNull('parent_id')
            ->where('active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success(CategoryResource::collection($categories));
    }

    /** Public. GET /categories/{category}/subcategories */
    #[OA\Get(
        path: '/categories/{category}/subcategories',
        tags: ['Categories'],
        summary: 'List subcategories of a category (public)',
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Subcategory list', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function subcategories(Category $category): JsonResponse
    {
        abort_unless($category->active, 404);

        $subcategories = $category->subcategories()
            ->where('active', true)
            ->with('image')
            ->withCount(['subcategoryProducts as products_count'])
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success(CategoryResource::collection($subcategories));
    }

    /** Admin only. POST /categories */
    #[OA\Post(
        path: '/categories',
        tags: ['Categories'],
        summary: 'Create category (admin)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            required: true,
            content: new OA\JsonContent(
                required: ['name'],
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                ]
            )
        ),
        responses: [
            new OA\Response(response: 201, description: 'Category created', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Category::class);

        $data = $request->validate([
            'parent_id' => ['nullable', 'exists:categories,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:categories,slug'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'age_gated' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);

        $data['slug'] = $data['slug'] ?? $this->uniqueSlug($data['name']);

        $category = Category::create($data);

        return $this->success(new CategoryResource($category), 'Category created.', 201);
    }

    /** Public. GET /categories/{category} */
    #[OA\Get(
        path: '/categories/{category}',
        tags: ['Categories'],
        summary: 'Get category (public)',
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Category details', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 404, description: 'Not found', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function show(Category $category): JsonResponse
    {
        abort_unless($category->active, 404);

        $category->load([
            'image',
            'subcategories' => fn ($q) => $q->where('active', true)->with('image')->withCount(['subcategoryProducts as products_count'])->orderBy('sort_order')->orderBy('name'),
        ])
            ->loadCount('products');

        return $this->success(new CategoryResource($category));
    }

    /** Admin only. PATCH /categories/{category} */
    #[OA\Patch(
        path: '/categories/{category}',
        tags: ['Categories'],
        summary: 'Update category (admin)',
        security: [['bearerAuth' => []]],
        requestBody: new OA\RequestBody(
            content: new OA\JsonContent(
                properties: [
                    new OA\Property(property: 'name', type: 'string', maxLength: 255),
                    new OA\Property(property: 'description', type: 'string', nullable: true),
                ]
            )
        ),
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Category updated', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function update(Request $request, Category $category): JsonResponse
    {
        $this->authorize('update', $category);

        $data = $request->validate([
            'parent_id' => ['nullable', 'exists:categories,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($category)],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0'],
            'age_gated' => ['sometimes', 'required', 'boolean'],
            'active' => ['sometimes', 'required', 'boolean'],
        ]);

        $category->update($data);

        return $this->success(new CategoryResource($category), 'Category updated.');
    }

    /** Admin only. DELETE /categories/{category} */
    #[OA\Delete(
        path: '/categories/{category}',
        tags: ['Categories'],
        summary: 'Delete category (admin)',
        security: [['bearerAuth' => []]],
        parameters: [
            new OA\Parameter(name: 'category', in: 'path', required: true, schema: new OA\Schema(type: 'integer')),
        ],
        responses: [
            new OA\Response(response: 200, description: 'Category deleted', content: new OA\JsonContent(ref: '#/components/schemas/SuccessResponse')),
            new OA\Response(response: 403, description: 'Forbidden', content: new OA\JsonContent(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function destroy(Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        $category->delete();

        return $this->success(message: 'Category deleted.');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'category';
        $slug = $base;
        $suffix = 1;

        while (Category::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }
}
