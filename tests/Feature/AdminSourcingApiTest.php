<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Jobs\ProcessSourcingProduct;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\Product\SourcingJob;
use App\Models\User;
use App\Services\Sourcing\ProductContentEnhancer;
use App\Services\Sourcing\SourcingCategoryResolver;
use App\Services\Sourcing\SupplierProductImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminSourcingApiTest extends TestCase
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
            'services.exchange_rates.base_url' => 'https://rates.test/v6',
            'services.exchange_rates.naira_markup' => 100,
            'services.exchange_rates.retries' => 1,
            'ai.openrouter.api_key' => 'test-openrouter-key',
            'ai.openrouter.base_url' => 'https://openrouter.test/api/v1',
            'ai.openrouter.product_content_models' => [
                'google/gemini-2.0-flash-lite-001',
                'deepseek/deepseek-v4-flash',
            ],
        ]);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_non_admin_cannot_use_sourcing_api(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/admin/sourcing/sources')
            ->assertForbidden();
    }

    public function test_admin_can_search_suppliers_and_see_import_state(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::create(['name' => 'Phones', 'slug' => 'phones', 'active' => true]);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Existing phone',
            'slug' => 'existing-phone',
            'price' => 100000,
            'stock' => 0,
            'status' => 'draft',
        ]);
        $product->sources()->create([
            'supplier' => 'amazon',
            'external_product_id' => 'B0EXISTING',
            'source_snapshot' => [],
        ]);
        Http::fake([
            'https://supplier.test/search*' => Http::response([
                'products' => [[
                    'id' => 'B0EXISTING',
                    'supplier_id' => 'B0EXISTING',
                    'supplier' => 'amazon',
                    'title' => 'Existing phone',
                ]],
                'total' => 1,
                'page' => 1,
                'supplier' => 'amazon',
            ]),
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/sourcing/search?source=amazon&q=phone')
            ->assertOk()
            ->assertJsonPath('data.products.0.imported', true)
            ->assertJsonPath('data.products.0.product_id', $product->id);

        Http::assertSent(fn ($request) => $request->hasHeader('X-Internal-Service-Secret', 'internal-secret'));
    }

    public function test_admin_can_import_supplier_product_as_draft_and_cannot_duplicate_it(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::create(['name' => 'Phones', 'slug' => 'phones', 'active' => true]);
        Http::fake([
            'https://supplier.test/details/amazon/B0TEST' => Http::response([
                'id' => 'B0TEST',
                'supplier_id' => 'B0TEST',
                'supplier' => 'amazon',
                'title' => 'Imported Phone',
                'description' => 'Supplier description',
                'price' => 249.99,
                'currency' => 'USD',
                'image_url' => 'https://images.test/phone.jpg',
                'product_url' => 'https://amazon.test/dp/B0TEST',
                'available' => true,
                'variants' => [['name' => 'Black']],
                'weight_lb' => 2.1,
                'us_delivery_days_min' => 2,
                'us_delivery_days_max' => 5,
            ]),
            'https://rates.test/v6/latest/USD' => Http::response([
                'result' => 'success',
                'rates' => ['NGN' => 1500],
            ]),
        ]);

        $payload = [
            'source' => 'amazon',
            'external_product_id' => 'B0TEST',
            'category_id' => $category->id,
            'name' => 'Limitra Enhanced Phone',
            'brand' => 'Example Brand',
            'description' => 'A clearer reviewed description.',
            'specifications' => [
                ['name' => 'Colour', 'value' => 'Black'],
            ],
        ];

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/sourcing/imports', $payload)
            ->assertCreated()
            ->assertJsonPath('data.supplier', 'amazon')
            ->assertJsonPath('data.product.name', 'Limitra Enhanced Phone')
            ->assertJsonPath('data.product.status', 'draft')
            ->assertJsonPath('data.product.stock', 0)
            ->assertJsonPath('data.product.price', '1392000.00')
            ->assertJsonPath('data.pricing_status', 'pending_approval')
            ->assertJsonPath('data.current_price_quote.status', 'proposed')
            ->assertJsonPath('data.product.specifications.0.name', 'Colour');

        $this->assertDatabaseHas('products', [
            'name' => 'Limitra Enhanced Phone',
            'brand' => 'Example Brand',
            'description' => 'A clearer reviewed description.',
            'currency' => 'NGN',
            'status' => 'draft',
            'stock' => 0,
        ]);
        $this->assertDatabaseHas('product_specifications', [
            'name' => 'Colour',
            'value' => 'Black',
        ]);
        $this->assertDatabaseHas('product_sources', [
            'supplier' => 'amazon',
            'external_product_id' => 'B0TEST',
            'validation_status' => 'pricing_review',
            'pricing_status' => 'pending_approval',
            'final_price_ngn' => 1392000,
        ]);
        $this->assertDatabaseHas('product_price_quotes', [
            'product_source_id' => 1,
            'status' => 'proposed',
            'proposed_price_ngn' => 1392000,
        ]);
        $this->assertDatabaseHas('images', [
            'imageable_type' => Product::class,
            'path' => 'https://images.test/phone.jpg',
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/sourcing/imports', $payload)
            ->assertConflict();

        Http::assertSentCount(2);
    }

    public function test_admin_can_generate_a_reviewable_ai_product_draft(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::create(['name' => 'Phones', 'slug' => 'phones', 'active' => true]);
        $subcategory = Category::create([
            'parent_id' => $category->id,
            'name' => 'Smartphones',
            'slug' => 'smartphones',
            'active' => true,
        ]);

        Http::fake([
            'https://supplier.test/details/ebay/EBAY-1' => Http::response([
                'supplier_id' => 'EBAY-1',
                'supplier' => 'ebay',
                'title' => 'Supplier Phone Title',
                'brand' => 'Example',
                'description' => 'Raw supplier description.',
                'price' => 100,
                'currency' => 'USD',
                'image_url' => 'https://images.test/phone.jpg',
                'product_url' => 'https://ebay.test/itm/EBAY-1',
                'available' => true,
                'final_price_ngn' => 250000,
            ]),
            'https://openrouter.test/api/v1/chat/completions' => Http::sequence()
                ->push(['choices' => [['message' => ['content' => 'not valid json']]]])
                ->push([
                    'choices' => [[
                        'message' => [
                            'content' => json_encode([
                                'name' => 'Example Smartphone',
                                'brand' => 'Example',
                                'description' => 'A polished and factual product description.',
                                'category_id' => $category->id,
                                'subcategory_id' => $subcategory->id,
                                'specifications' => [
                                    ['name' => 'Colour', 'value' => 'Black'],
                                ],
                            ]),
                        ],
                    ]],
                ]),
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/sourcing/enhance', [
                'source' => 'ebay',
                'external_product_id' => 'EBAY-1',
            ])
            ->assertOk()
            ->assertJsonPath('data.supplier_product.price', 100)
            ->assertJsonPath('data.enhanced.name', 'Example Smartphone')
            ->assertJsonPath('data.enhanced.category_id', $category->id)
            ->assertJsonPath('data.enhanced.subcategory_id', $subcategory->id)
            ->assertJsonPath('data.enhanced.specifications.0.name', 'Colour');

        Http::assertSent(fn ($request) => $request->url() === 'https://openrouter.test/api/v1/chat/completions'
            && $request['model'] === 'deepseek/deepseek-v4-flash'
            && $request->hasHeader('Authorization', 'Bearer test-openrouter-key'));
    }

    public function test_import_requires_subcategory_to_belong_to_category(): void
    {
        $admin = User::factory()->admin()->create();
        $category = Category::create(['name' => 'Phones', 'slug' => 'phones', 'active' => true]);
        $other = Category::create(['name' => 'Clothes', 'slug' => 'clothes', 'active' => true]);
        $subcategory = Category::create([
            'parent_id' => $other->id,
            'name' => 'Dresses',
            'slug' => 'dresses',
            'active' => true,
        ]);

        $this->actingAs($admin, 'api')->postJson('/api/v1/admin/sourcing/imports', [
            'source' => 'ebay',
            'external_product_id' => '123',
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('subcategory_id');

        Http::assertNothingSent();
    }

    public function test_admin_can_queue_ai_rewrite_and_it_creates_a_draft_in_the_background(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();
        $category = Category::create(['name' => 'Audio', 'slug' => 'audio', 'active' => true]);

        Http::fake([
            'https://supplier.test/details/ebay/EBAY-QUEUE' => Http::response([
                'supplier_id' => 'EBAY-QUEUE',
                'supplier' => 'ebay',
                'title' => 'Supplier Headphones',
                'description' => 'Raw description.',
                'price' => 40,
                'currency' => 'USD',
                'image_url' => 'http://i.ebayimg.test/headphones.jpg',
                'product_url' => 'https://ebay.test/itm/EBAY-QUEUE',
                'available' => true,
            ]),
            'https://openrouter.test/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'name' => 'Wireless Over-Ear Headphones',
                        'brand' => '',
                        'description' => 'Comfortable wireless headphones for everyday listening.',
                        'category_id' => $category->id,
                        'subcategory_id' => null,
                        'specifications' => [],
                    ])],
                ]],
            ]),
        ]);

        $response = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/sourcing/jobs', [
                'products' => [[
                    'source' => 'ebay',
                    'external_product_id' => 'EBAY-QUEUE',
                ]],
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.0.status', 'queued');

        $jobRecord = SourcingJob::findOrFail($response->json('data.0.id'));
        Queue::assertPushedOn('ai', ProcessSourcingProduct::class);

        (new ProcessSourcingProduct($jobRecord->id))->handle(
            app(ProductContentEnhancer::class),
            app(SupplierProductImportService::class),
            app(SourcingCategoryResolver::class),
        );

        $jobRecord->refresh();
        $this->assertSame('completed', $jobRecord->status);
        $this->assertNotNull($jobRecord->product_id);
        $this->assertNotNull($jobRecord->warning);
        $this->assertDatabaseHas('products', [
            'id' => $jobRecord->product_id,
            'name' => 'Wireless Over-Ear Headphones',
            'price' => 0,
            'status' => 'draft',
        ]);
        $this->assertDatabaseHas('product_sources', [
            'product_id' => $jobRecord->product_id,
            'sync_status' => 'needs_review',
            'validation_status' => 'manual_review',
            'pricing_status' => 'pending_inputs',
        ]);
        $this->assertDatabaseHas('images', [
            'imageable_id' => $jobRecord->product_id,
            'path' => 'https://i.ebayimg.test/headphones.jpg',
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/sourcing/jobs')
            ->assertOk()
            ->assertJsonPath('data.items.0.status', 'completed')
            ->assertJsonPath('data.items.0.product_id', $jobRecord->product_id);
    }

    public function test_background_job_uses_hidden_fallback_when_ai_cannot_match_a_category(): void
    {
        $admin = User::factory()->admin()->create();
        Category::create(['name' => 'Fashion', 'slug' => 'fashion', 'active' => true]);

        Http::fake([
            'https://supplier.test/details/ebay/EBAY-UNMATCHED' => Http::response([
                'supplier_id' => 'EBAY-UNMATCHED',
                'supplier' => 'ebay',
                'title' => 'Wireless Headphones',
                'description' => 'Raw description.',
                'price' => 40,
                'currency' => 'USD',
                'available' => true,
                'weight_lb' => 1,
                'us_delivery_days_min' => 2,
                'us_delivery_days_max' => 5,
            ]),
            'https://openrouter.test/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'message' => ['content' => json_encode([
                        'name' => 'Wireless Headphones',
                        'brand' => '',
                        'description' => 'Wireless headphones for everyday listening.',
                        'category_id' => null,
                        'subcategory_id' => null,
                        'specifications' => [],
                    ])],
                ]],
            ]),
            'https://rates.test/v6/latest/USD' => Http::response([
                'result' => 'success',
                'rates' => ['NGN' => 1500],
            ]),
        ]);

        $jobRecord = SourcingJob::create([
            'user_id' => $admin->id,
            'supplier' => 'ebay',
            'external_product_id' => 'EBAY-UNMATCHED',
            'status' => 'queued',
        ]);

        (new ProcessSourcingProduct($jobRecord->id))->handle(
            app(ProductContentEnhancer::class),
            app(SupplierProductImportService::class),
            app(SourcingCategoryResolver::class),
        );

        $jobRecord->refresh();
        $fallback = Category::query()->where('slug', 'uncategorized')->firstOrFail();

        $this->assertSame('completed', $jobRecord->status);
        $this->assertFalse($fallback->active);
        $this->assertSame(32767, $fallback->sort_order);
        $this->assertStringContainsString('placed in Uncategorized', (string) $jobRecord->warning);
        $this->assertDatabaseHas('products', [
            'id' => $jobRecord->product_id,
            'category_id' => $fallback->id,
            'name' => 'Wireless Headphones',
            'status' => 'draft',
        ]);
    }
}
