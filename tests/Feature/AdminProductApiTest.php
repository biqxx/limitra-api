<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminProductApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_admin_lists_and_filters_products_across_catalog_statuses(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category();
        $this->product($category, ['name' => 'Active Phone', 'slug' => 'active-phone', 'status' => 'active']);
        $draft = $this->product($category, ['name' => 'Draft Phone', 'slug' => 'draft-phone', 'status' => 'draft']);
        $this->product($category, ['name' => 'Archived Phone', 'slug' => 'archived-phone', 'status' => 'archived']);
        $deleted = $this->product($category, ['name' => 'Deleted Phone', 'slug' => 'deleted-phone', 'status' => 'draft']);
        $deleted->delete();

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/products?status=draft&q=Draft')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $draft->id)
            ->assertJsonPath('data.items.0.status', 'draft');

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/products?trashed=with&per_page=100')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 4);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/products?trashed=only')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $deleted->id);
    }

    public function test_admin_can_create_edit_delete_view_and_restore_a_product(): void
    {
        $admin = User::factory()->admin()->create();
        $category = $this->category();

        $created = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/products', [
                'category_id' => $category->id,
                'name' => 'Admin Product',
                'sku' => 'ADMIN-001',
                'price' => 25000,
                'stock' => 6,
                'status' => 'draft',
            ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'draft');

        $productId = $created->json('data.id');

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/products/{$productId}", [
                'name' => 'Published Admin Product',
                'status' => 'active',
                'stock' => 9,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Published Admin Product')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.stock', 9);

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/v1/admin/products/{$productId}")
            ->assertOk()
            ->assertJsonPath('message', 'Product deleted.');

        $this->assertSoftDeleted('products', ['id' => $productId]);

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/admin/products/{$productId}")
            ->assertOk()
            ->assertJsonPath('data.id', $productId)
            ->assertJsonPath('data.status', 'active');

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/products/{$productId}/restore")
            ->assertOk()
            ->assertJsonPath('message', 'Product restored.');

        $this->assertDatabaseHas('products', ['id' => $productId, 'deleted_at' => null]);
    }

    public function test_non_admin_cannot_access_admin_product_routes(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/admin/products')
            ->assertForbidden();
    }

    private function category(): Category
    {
        return Category::query()->create([
            'name' => 'Phones',
            'slug' => 'phones',
            'active' => true,
        ]);
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
