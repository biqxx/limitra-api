<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use Database\Seeders\CatalogCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCategoryApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_admin_lists_active_and_inactive_category_tree_with_product_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category(['name' => 'Phones', 'slug' => 'phones']);
        $subcategory = $this->category([
            'parent_id' => $category->id,
            'name' => 'Smartphones',
            'slug' => 'phones-smartphones',
            'active' => false,
        ]);
        $this->product($category, ['subcategory_id' => $subcategory->id]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/categories')
            ->assertOk()
            ->assertJsonPath('data.0.id', $category->id)
            ->assertJsonPath('data.0.products_count', 1)
            ->assertJsonPath('data.0.subcategories.0.id', $subcategory->id)
            ->assertJsonPath('data.0.subcategories.0.active', false)
            ->assertJsonPath('data.0.subcategories.0.products_count', 1);
    }

    public function test_admin_can_create_and_update_a_subcategory(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category(['name' => 'Electronics', 'slug' => 'electronics']);

        $created = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/categories', [
                'parent_id' => $category->id,
                'name' => 'Smart Home',
                'sort_order' => 3,
                'active' => true,
            ])
            ->assertCreated()
            ->assertJsonPath('data.parent_id', $category->id)
            ->assertJsonPath('data.name', 'Smart Home');

        $subcategoryId = $created->json('data.id');

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/categories/{$subcategoryId}", [
                'name' => 'Connected Home',
                'active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Connected Home')
            ->assertJsonPath('data.active', false);
    }

    public function test_category_deletion_is_blocked_while_it_has_children_or_products(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category(['name' => 'Home', 'slug' => 'home']);
        $subcategory = $this->category([
            'parent_id' => $category->id,
            'name' => 'Kitchen',
            'slug' => 'home-kitchen',
        ]);

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/v1/categories/{$category->id}")
            ->assertStatus(409);

        $product = $this->product($category, ['subcategory_id' => $subcategory->id]);

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/v1/categories/{$subcategory->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('products', ['id' => $product->id]);
        $this->assertDatabaseHas('categories', ['id' => $subcategory->id]);
    }

    public function test_empty_category_can_be_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category(['name' => 'Unused', 'slug' => 'unused']);

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/v1/categories/{$category->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Category deleted.');

        $this->assertDatabaseMissing('categories', ['id' => $category->id]);
    }

    public function test_non_admin_cannot_list_admin_categories(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/admin/categories')
            ->assertForbidden();
    }

    public function test_catalog_category_seeder_adds_storefront_taxonomy_idempotently(): void
    {
        $this->seed(CatalogCategorySeeder::class);
        $this->seed(CatalogCategorySeeder::class);

        $this->assertSame(13, Category::query()->whereNull('parent_id')->count());
        $this->assertSame(137, Category::query()->whereNotNull('parent_id')->count());
        $this->assertDatabaseHas('categories', [
            'slug' => 'adult',
            'name' => 'Wellness & Intimacy',
            'age_gated' => true,
            'active' => true,
        ]);
        $this->assertDatabaseHas('categories', [
            'slug' => 'phones-smartphones',
            'name' => 'Smartphones',
            'active' => true,
        ]);
    }

    private function category(array $attributes): Category
    {
        return Category::query()->create(array_merge([
            'active' => true,
            'sort_order' => 1,
            'age_gated' => false,
        ], $attributes));
    }

    private function product(Category $category, array $attributes = []): Product
    {
        return Product::query()->create(array_merge([
            'category_id' => $category->id,
            'name' => 'Product',
            'slug' => fake()->unique()->slug(),
            'price' => 10000,
            'stock' => 5,
            'low_stock_threshold' => 2,
            'status' => 'active',
        ], $attributes));
    }
}
