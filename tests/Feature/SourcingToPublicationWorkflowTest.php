<?php

namespace Tests\Feature;

use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SourcingToPublicationWorkflowTest extends TestCase
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
        ]);

        $this->artisan('db:seed', ['--class' => 'CatalogCategorySeeder']);
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_14_000001_centralize_product_pricing.php']);
    }

    public function test_complete_sourcing_to_draft_to_price_approval_to_publication_workflow(): void
    {
        $admin = User::factory()->admin()->create();

        Http::fake([
            'https://rates.test/v6/latest/USD' => Http::response([
                'result' => 'success',
                'rates' => ['NGN' => 1500],
            ]),
            'https://supplier.test/details/amazon/B0TEST999' => Http::response([
                'id' => 'B0TEST999',
                'supplier_id' => 'B0TEST999',
                'supplier' => 'amazon',
                'title' => 'Anker USB-C Fast Charger 65W',
                'brand' => 'Anker',
                'description' => 'Original fast wall charger for laptop and phone.',
                'price' => 50.0,
                'currency' => 'USD',
                'available' => true,
                'weight_lb' => 0.8,
                'us_delivery_days_min' => 2,
                'us_delivery_days_max' => 5,
                'product_url' => 'https://amazon.com/dp/B0TEST999',
                'image_url' => 'https://images.test/charger.jpg',
                'specifications' => [
                    ['name' => 'Wattage', 'value' => '65W'],
                ],
            ]),
        ]);

        $category = Category::where('slug', 'electronics')->firstOrFail();
        $subcategory = $category->subcategories()->firstOrFail();

        // Step 1: Admin imports the supplier product
        $importResponse = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/sourcing/imports', [
                'source' => 'amazon',
                'external_product_id' => 'B0TEST999',
                'category_id' => $category->id,
                'subcategory_id' => $subcategory->id,
            ])
            ->assertCreated()
            ->assertJsonPath('data.supplier', 'amazon')
            ->assertJsonPath('data.external_product_id', 'B0TEST999')
            ->assertJsonPath('data.pricing_status', 'pending_approval');

        $sourceId = $importResponse->json('data.id');
        $productId = $importResponse->json('data.product.id');

        // Step 2: Verify draft product was created with draft status
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'status' => 'draft',
        ]);

        $this->assertDatabaseHas('product_sources', [
            'id' => $sourceId,
            'product_id' => $productId,
            'supplier' => 'amazon',
            'external_product_id' => 'B0TEST999',
            'price_review_required' => true,
        ]);

        // Step 3: Direct activation must fail because price quote is unapproved
        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/products/{$productId}", [
                'status' => 'active',
            ])
            ->assertStatus(422);

        // Step 4: Admin reviews current price quote
        $pricingResponse = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/admin/sourcing/imports/{$sourceId}/pricing")
            ->assertOk()
            ->assertJsonPath('data.source.pricing_status', 'pending_approval')
            ->assertJsonStructure([
                'data' => [
                    'source' => [
                        'current_price_quote' => [
                            'id',
                            'proposed_price_ngn',
                            'cost_breakdown',
                            'rules_snapshot',
                        ],
                    ],
                    'quotes',
                ],
            ]);

        $quoteId = $pricingResponse->json('data.source.current_price_quote.id');
        $proposedPrice = $pricingResponse->json('data.source.current_price_quote.proposed_price_ngn');
        $this->assertNotNull($proposedPrice);

        // Step 5: Admin recalculates price with custom updated weight and supplier price
        $recalculated = $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/sourcing/imports/{$sourceId}/pricing/recalculate", [
                'supplier_price' => 55.0,
                'supplier_currency' => 'USD',
                'weight_lb' => 1.0,
                'delivery_days_min' => 2,
                'delivery_days_max' => 6,
            ])
            ->assertOk()
            ->assertJsonPath('data.pricing_status', 'pending_approval');

        $newQuoteId = $recalculated->json('data.current_price_quote.id');
        $newProposedPrice = $recalculated->json('data.current_price_quote.proposed_price_ngn');

        // Step 6: Admin approves the recalculated price quote
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/sourcing/imports/{$sourceId}/pricing/approve", [
                'quote_id' => $newQuoteId,
            ])
            ->assertOk()
            ->assertJsonPath('data.pricing_status', 'approved')
            ->assertJsonPath('data.final_price_ngn', $newProposedPrice);

        $this->assertDatabaseHas('product_price_quotes', [
            'id' => $newQuoteId,
            'status' => 'approved',
            'approved_by' => $admin->id,
        ]);

        // Step 7: Admin publishes the product
        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/products/{$productId}", [
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.price', $newProposedPrice);

        // Step 8: Verify public storefront product endpoint serves the published product
        $this->getJson("/api/v1/products/{$productId}")
            ->assertOk()
            ->assertJsonPath('data.id', $productId)
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.price', $newProposedPrice);
    }
}
