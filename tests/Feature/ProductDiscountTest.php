<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Product\Product;
use App\Models\Product\ProductSource;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductDiscountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_admin_can_slash_calculated_price_on_sourced_product_with_infinite_duration(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create([
            'price' => 50000.00,
            'status' => 'active',
        ]);
        ProductSource::factory()->create([
            'product_id' => $product->id,
            'pricing_status' => 'approved',
            'final_price_ngn' => 50000.00,
        ]);

        // Direct price mutation on sourced products remains guarded.
        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/products/{$product->id}", ['price' => 40000])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price');

        // Dedicated discount endpoint slashes the calculated price and keeps discount_ends_at null (infinite).
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/products/{$product->id}/discount", [
                'discounted_price' => 40000,
            ])
            ->assertOk()
            ->assertJsonPath('data.price', '40000.00')
            ->assertJsonPath('data.compare_at_price', '50000.00')
            ->assertJsonPath('data.discount_active', true)
            ->assertJsonPath('data.discount_ends_at', null)
            ->assertJsonPath('data.discount_remaining_seconds', null)
            ->assertJsonPath('data.discount_percentage', 20);

        // Public product API reflects the slashed price and infinite discount.
        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.price', '40000.00')
            ->assertJsonPath('data.compare_at_price', '50000.00')
            ->assertJsonPath('data.discount_active', true)
            ->assertJsonPath('data.discount_remaining_seconds', null);

        // Scheduled expiry command leaves infinite discounts untouched.
        $this->artisan('products:expire-discounts')->assertSuccessful();
        $product->refresh();
        $this->assertSame('40000.00', number_format((float) $product->price, 2, '.', ''));
        $this->assertSame('50000.00', number_format((float) $product->compare_at_price, 2, '.', ''));
    }

    public function test_admin_can_apply_percentage_discount_with_timer_and_remove_it(): void
    {
        $admin = User::factory()->admin()->create();
        $product = Product::factory()->create([
            'price' => 10000.00,
            'status' => 'active',
        ]);
        ProductSource::factory()->create([
            'product_id' => $product->id,
            'pricing_status' => 'approved',
            'final_price_ngn' => 10000.00,
        ]);

        $startsAt = now()->subHour()->toIso8601String();
        $endsAt = now()->addHours(2)->toIso8601String();

        $response = $this->actingAs($admin, 'api')
            ->postJson("/api/v1/products/{$product->id}/discount", [
                'discount_percentage' => 25,
                'discount_starts_at' => $startsAt,
                'discount_ends_at' => $endsAt,
            ])
            ->assertOk()
            ->assertJsonPath('data.price', '7500.00')
            ->assertJsonPath('data.compare_at_price', '10000.00')
            ->assertJsonPath('data.discount_active', true)
            ->assertJsonPath('data.discount_percentage', 25);

        $this->assertNotNull($response->json('data.discount_remaining_seconds'));

        // Removing the discount restores the original calculated price.
        $this->actingAs($admin, 'api')
            ->deleteJson("/api/v1/admin/products/{$product->id}/discount")
            ->assertOk()
            ->assertJsonPath('data.price', '10000.00')
            ->assertJsonPath('data.compare_at_price', null)
            ->assertJsonPath('data.discount_active', false)
            ->assertJsonPath('data.discount_percentage', 0);
    }

    public function test_expired_discount_reverts_on_read_and_via_expiry_command(): void
    {
        $product = Product::factory()->create([
            'price' => 8000.00,
            'compare_at_price' => 10000.00,
            'discount_starts_at' => now()->subDays(2),
            'discount_ends_at' => now()->subMinute(),
            'status' => 'active',
        ]);

        // Even before the scheduled command runs, effective pricing reverts to compare_at_price.
        $this->getJson("/api/v1/products/{$product->id}")
            ->assertOk()
            ->assertJsonPath('data.price', '10000.00')
            ->assertJsonPath('data.compare_at_price', null)
            ->assertJsonPath('data.discount_active', false)
            ->assertJsonPath('data.discount_remaining_seconds', null);

        $this->artisan('products:expire-discounts')->assertSuccessful();
        $product->refresh();
        $this->assertSame('10000.00', number_format((float) $product->price, 2, '.', ''));
        $this->assertNull($product->compare_at_price);
        $this->assertNull($product->discount_ends_at);
    }
}

