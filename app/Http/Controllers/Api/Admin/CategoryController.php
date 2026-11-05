<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\BaseController;
use App\Http\Resources\CategoryResource;
use App\Models\Product\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends BaseController
{
    public function index(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->with([
                'image',
                'subcategories' => fn ($query) => $query
                    ->with('image')
                    ->withCount(['subcategoryProducts as products_count'])
                    ->orderBy('sort_order')
                    ->orderBy('name'),
            ])
            ->withCount('products')
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $this->success(CategoryResource::collection($categories)->resolve($request));
    }

    public function show(Request $request, Category $category): JsonResponse
    {
        $category->load([
            'image',
            'subcategories' => fn ($query) => $query
                ->with('image')
                ->withCount(['subcategoryProducts as products_count'])
                ->orderBy('sort_order')
                ->orderBy('name'),
        ])->loadCount('products');

        return $this->success((new CategoryResource($category))->resolve($request));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => ['nullable', Rule::exists('categories', 'id')->whereNull('parent_id')],
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['nullable', 'string', 'max:255', 'unique:categories,slug'],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['nullable', 'integer', 'between:0,32767'],
            'age_gated' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
        ]);

        $data['slug'] = $data['slug'] ?? $this->uniqueSlug($data['name']);

        $category = Category::create($data);

        return $this->success(new CategoryResource($category), 'Category created.', 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $data = $request->validate([
            'parent_id' => [
                'nullable',
                Rule::exists('categories', 'id')->whereNull('parent_id'),
                Rule::notIn([$category->id]),
            ],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'slug' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($category)],
            'description' => ['nullable', 'string'],
            'icon' => ['nullable', 'string', 'max:100'],
            'sort_order' => ['sometimes', 'required', 'integer', 'between:0,32767'],
            'age_gated' => ['sometimes', 'required', 'boolean'],
            'active' => ['sometimes', 'required', 'boolean'],
        ]);

        if (($data['parent_id'] ?? null) !== null && $category->subcategories()->exists()) {
            return $this->error('A category with subcategories cannot become a subcategory.', 422);
        }

        $category->update($data);

        return $this->success(new CategoryResource($category), 'Category updated.');
    }

    public function destroy(Category $category): JsonResponse
    {
        if ($category->subcategories()->exists()) {
            return $this->error("Remove or move this category's subcategories before deleting it.", 409);
        }

        if ($category->products()->exists() || $category->subcategoryProducts()->exists()) {
            return $this->error('This category is assigned to products. Reassign them before deleting it.', 409);
        }

        $category->delete();

        return $this->success(null, 'Category deleted.');
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
