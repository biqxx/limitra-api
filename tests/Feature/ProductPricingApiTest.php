<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Jobs\DispatchProductRepricing;
use App\Models\Product\Product;
use App\Models\Product\ProductSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProductPricingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.secret' => 'test-secret-with-at-least-thirty-two-characters',
            'services.exchange_rates.base_url' => 'https://rates.test/v6',
            'services.exchange_rates.retries' => 1,
        ]);
        Cache::flush();
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_incomplete_supplier_price_cannot_be_published_or_manually_changed(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['price' => 0]);
        ProductSource::factory()->create([
            'product_id' => $product->id,
            'supplier_price' => null,
            'weight_lb' => null,
            'delivery_days_max' => null,
        ]);

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/products/{$product->id}", ['status' => 'active'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('status');

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/products/{$product->id}", ['price' => 100000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price');
    }

    public function test_admin_can_calculate_approve_and_publish_a_supplier_price(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['price' => 0]);
        $source = ProductSource::factory()->create([
            'product_id' => $product->id,
            'supplier_price' => null,
            'weight_lb' => null,
            'delivery_days_max' => null,
        ]);
        Http::fake([
            'https://rates.test/v6/latest/USD' => Http::response([
                'result' => 'success',
                'rates' => ['NGN' => 1500],
            ]),
        ]);

        $recalculated = $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/sourcing/imports/{$source->id}/pricing/recalculate", [
                'supplier_price' => 100,
                'supplier_currency' => 'USD',
                'weight_lb' => 1.2,
                'delivery_days_min' => 2,
                'delivery_days_max' => 5,
            ])
            ->assertOk()
            ->assertJsonPath('data.pricing_status', 'pending_approval')
            ->assertJsonPath('data.current_price_quote.proposed_price_ngn', '576000.00');

        $quoteId = $recalculated->json('data.current_price_quote.id');

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/sourcing/imports/{$source->id}/pricing/approve", [
                'quote_id' => $quoteId,
            ])
            ->assertOk()
            ->assertJsonPath('data.pricing_status', 'approved')
            ->assertJsonPath('data.final_price_ngn', '576000.00');

        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/products/{$product->id}", ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.price', '576000.00');

        $this->assertDatabaseHas('product_price_quotes', [
            'id' => $quoteId,
            'status' => 'approved',
            'approved_by' => $admin->id,
            'approved_price_ngn' => 576000,
        ]);
    }

    public function test_admin_can_calculate_a_supplier_price_without_weight(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create(['price' => 0]);
        $source = ProductSource::factory()->create([
            'product_id' => $product->id,
            'supplier_price' => 100,
            'supplier_currency' => 'USD',
            'weight_lb' => null,
            'delivery_days_min' => 2,
            'delivery_days_max' => 5,
        ]);
        Http::fake([
            'https://rates.test/v6/latest/USD' => Http::response([
                'result' => 'success',
                'rates' => ['NGN' => 1500],
            ]),
        ]);

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/sourcing/imports/{$source->id}/pricing/recalculate")
            ->assertOk()
            ->assertJsonPath('data.pricing_status', 'pending_approval')
            ->assertJsonPath('data.current_price_quote.proposed_price_ngn', '528000.00')
            ->assertJsonPath('data.current_price_quote.missing_inputs', [])
            ->assertJsonPath('data.current_price_quote.cost_breakdown.weight_missing', true)
            ->assertJsonPath('data.current_price_quote.cost_breakdown.chargeable_weight_lb', 0)
            ->assertJsonPath('data.current_price_quote.cost_breakdown.air_cargo_ngn', 0);
    }

    public function test_recalculation_preserves_quote_history_and_flags_a_large_change(): void
    {
        $admin = User::factory()->admin()->create();
        $source = ProductSource::factory()->create();
        Http::fake([
            'https://rates.test/v6/latest/USD' => Http::response([
                'result' => 'success',
                'rates' => ['NGN' => 1500],
            ]),
        ]);

        $first = $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/sourcing/imports/{$source->id}/pricing/recalculate")
            ->assertOk();

        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/sourcing/imports/{$source->id}/pricing/approve", [
                'quote_id' => $first->json('data.current_price_quote.id'),
            ])
            ->assertOk();

        $second = $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/sourcing/imports/{$source->id}/pricing/recalculate", [
                'supplier_price' => 150,
            ])
            ->assertOk()
            ->assertJsonPath('data.current_price_quote.cost_breakdown.urgent_review', true);

        $this->assertNotSame(
            $first->json('data.current_price_quote.id'),
            $second->json('data.current_price_quote.id'),
        );
        $this->assertDatabaseCount('product_price_quotes', 2);
        $this->assertDatabaseHas('product_price_quotes', [
            'id' => $first->json('data.current_price_quote.id'),
            'status' => 'approved',
        ]);
    }

    public function test_pricing_setting_change_queues_catalog_repricing(): void
    {
        Queue::fake();
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin, 'api')
            ->patchJson('/api/v1/admin/settings', [
                'settings' => [[
                    'key' => 'pricing.fx_buffer_ngn_minor',
                    'value' => 12000,
                ]],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.value', 12000);

        Queue::assertPushed(DispatchProductRepricing::class);
    }
}
