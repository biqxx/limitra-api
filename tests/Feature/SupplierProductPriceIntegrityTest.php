<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Jobs\ProcessSourcingProduct;
use App\Models\Product\Category;
use App\Models\Product\SourcingJob;
use App\Models\User;
use App\Services\Sourcing\ProductContentEnhancer;
use App\Services\Sourcing\SourcingCategoryResolver;
use App\Services\Sourcing\SupplierProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SupplierProductPriceIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => 'test-secret-with-at-least-thirty-two-characters',
            'services.supplier.base_url' => 'https://supplier.test',
            'services.supplier.secret' => 'internal-secret',
            'services.supplier.retries' => 1,
            'ai.openrouter.api_key' => 'test-openrouter-key',
            'ai.openrouter.base_url' => 'https://openrouter.test/api/v1',
            'ai.openrouter.product_content_models' => ['google/gemini-2.0-flash-lite-001'],
        ]);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_import_rejects_missing_supplier_price(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::create([
            'name' => 'Audio',
            'slug' => 'audio',
            'active' => true,
        ]);
        Http::fake([
            'https://supplier.test/details/amazon/B0NOPRICE' => Http::response([
                'supplier_id' => 'B0NOPRICE',
                'supplier' => 'amazon',
                'title' => 'Supplier Headphones',
                'price' => null,
                'currency' => 'USD',
                'data_quality' => [
                    'price_verified' => false,
                    'missing_fields' => ['price'],
                ],
            ]),
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/sourcing/imports', [
                'source' => 'amazon',
                'external_product_id' => 'B0NOPRICE',
                'category_id' => $category->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'supplier_price_unavailable')
            ->assertJsonPath('errors.missing_fields.0', 'price');

        $this->assertDatabaseCount('products', 0);
        $this->assertDatabaseCount('product_sources', 0);
    }

    public function test_import_rejects_unverified_zero_price(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::create([
            'name' => 'Audio',
            'slug' => 'audio',
            'active' => true,
        ]);
        Http::fake([
            'https://supplier.test/details/ebay/ZERO-PRICE' => Http::response([
                'supplier_id' => 'ZERO-PRICE',
                'supplier' => 'ebay',
                'title' => 'Supplier Headphones',
                'price' => 0,
                'currency' => 'USD',
            ]),
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/sourcing/imports', [
                'source' => 'ebay',
                'external_product_id' => 'ZERO-PRICE',
                'category_id' => $category->id,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'supplier_price_unavailable');

        $this->assertDatabaseCount('products', 0);
    }

    public function test_background_import_fails_without_retrying_invalid_supplier_price(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::create([
            'name' => 'Audio',
            'slug' => 'audio',
            'active' => true,
        ]);
        Http::fake([
            'https://supplier.test/details/amazon/B0NOPRICE' => Http::response([
                'supplier_id' => 'B0NOPRICE',
                'supplier' => 'amazon',
                'title' => 'Supplier Headphones',
                'price' => null,
                'currency' => null,
                'data_quality' => [
                    'price_verified' => false,
                    'missing_fields' => ['price', 'currency'],
                ],
            ]),
            'https://openrouter.test/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'name' => 'Wireless Headphones',
                        'brand' => '',
                        'description' => 'Wireless headphones.',
                        'category_id' => $category->id,
                        'subcategory_id' => null,
                        'specifications' => [],
                    ])],
                ]],
            ]),
        ]);
        $record = SourcingJob::create([
            'user_id' => $admin->id,
            'supplier' => 'amazon',
            'external_product_id' => 'B0NOPRICE',
            'status' => 'queued',
        ]);

        (new ProcessSourcingProduct($record->id))->handle(
            app(ProductContentEnhancer::class),
            app(SupplierProductImportService::class),
            app(SourcingCategoryResolver::class),
        );

        $record->refresh();
        $this->assertSame('failed', $record->status);
        $this->assertSame(1, $record->attempts);
        $this->assertStringContainsString('reliable price', (string) $record->error);
        $this->assertDatabaseCount('products', 0);
    }
}
