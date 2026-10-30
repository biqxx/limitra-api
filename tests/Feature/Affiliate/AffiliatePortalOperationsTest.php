<?php

namespace Tests\Feature\Affiliate;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliatePayoutMethod;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffiliatePortalOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_user_can_submit_affiliate_application_and_check_status(): void
    {
        $user = User::factory()->create(['role' => 'user']);

        // Apply
        $response = $this->actingAs($user, 'api')
            ->postJson('/api/v1/affiliate/applications', [
                'promotion_channels' => ['youtube', 'twitter'],
                'website_url' => 'https://example.com/reviews',
                'notes' => 'Tech reviewer with 50k followers.',
            ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.application.status', 'pending');

        $this->assertDatabaseHas('affiliate_applications', [
            'user_id' => $user->id,
            'status' => 'pending',
            'website_url' => 'https://example.com/reviews',
        ]);

        // Duplicate attempt fails
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/affiliate/applications', [
                'promotion_channels' => ['instagram'],
            ])
            ->assertStatus(422);

        // Check status
        $this->actingAs($user, 'api')
            ->getJson('/api/v1/affiliate/application')
            ->assertOk()
            ->assertJsonPath('data.has_application', true)
            ->assertJsonPath('data.application.status', 'pending');
    }

    public function test_affiliate_can_manage_custom_referral_links(): void
    {
        $user = User::factory()->create(['role' => 'affiliate']);
        $affiliate = Affiliate::create([
            'user_id' => $user->id,
            'slug' => 'portal-links',
            'code' => 'LINKS01',
            'status' => 'active',
        ]);

        $category = Category::create(['name' => 'Phones', 'slug' => 'phones']);
        $product = Product::create([
            'category_id' => $category->id,
            'name' => 'Flagship Phone',
            'slug' => 'flagship-phone',
            'sku' => 'FLG-001',
            'price' => 500.00,
            'stock' => 5,
            'status' => 'active',
        ]);

        // Store custom link
        $createRes = $this->actingAs($user, 'api')
            ->postJson('/api/v1/affiliate/links', [
                'product_id' => $product->id,
                'campaign' => 'black-friday',
            ]);

        $createRes->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.link.campaign', 'black-friday');

        $linkId = $createRes->json('data.link.id');

        // List links
        $this->actingAs($user, 'api')
            ->getJson('/api/v1/affiliate/links')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonCount(1, 'data.items');

        // Deactivate link
        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/affiliate/links/{$linkId}")
            ->assertOk();

        $this->assertDatabaseHas('affiliate_links', [
            'id' => $linkId,
            'is_active' => false,
        ]);
    }

    public function test_affiliate_can_create_promo_code_and_payout_methods(): void
    {
        $user = User::factory()->create(['role' => 'affiliate']);
        $affiliate = Affiliate::create([
            'user_id' => $user->id,
            'slug' => 'portal-promo',
            'code' => 'PROMO01',
            'status' => 'active',
        ]);

        // Create promo code
        $promoRes = $this->actingAs($user, 'api')
            ->postJson('/api/v1/affiliate/promo-codes', [
                'code' => 'TECH5OFF',
                'discount_percent' => 5,
            ]);

        $promoRes->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.code', 'TECH5OFF');

        $promoId = $promoRes->json('data.id');

        // List promo codes
        $this->actingAs($user, 'api')
            ->getJson('/api/v1/affiliate/promo-codes')
            ->assertOk()
            ->assertJsonCount(1, 'data.items');

        // Deactivate promo code
        $this->actingAs($user, 'api')
            ->deleteJson("/api/v1/affiliate/promo-codes/{$promoId}")
            ->assertOk();

        // Add payout method
        $methodRes = $this->actingAs($user, 'api')
            ->postJson('/api/v1/affiliate/payout-methods', [
                'bank_name' => 'Zenith Bank',
                'account_number' => '1234567890',
                'account_name' => 'John Doe Affiliate',
            ]);

        $methodRes->assertCreated()
            ->assertJsonPath('data.bank_name', 'Zenith Bank')
            ->assertJsonPath('data.account_number_masked', '******7890')
            ->assertJsonPath('data.is_default', true);

        $methodId = $methodRes->json('data.id');

        // List payout methods
        $this->actingAs($user, 'api')
            ->getJson('/api/v1/affiliate/payout-methods')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_affiliate_withdrawal_lifecycle_and_balance_validation(): void
    {
        $user = User::factory()->create(['role' => 'affiliate']);
        $affiliate = Affiliate::create([
            'user_id' => $user->id,
            'slug' => 'portal-wth',
            'code' => 'WTH01',
            'status' => 'active',
            'total_earnings' => 50000.00,
            'total_paid' => 10000.00, // Available: 40000.00
        ]);

        $method = AffiliatePayoutMethod::create([
            'affiliate_id' => $affiliate->id,
            'bank_name' => 'GTBank',
            'account_number' => '0123456789',
            'account_name' => 'John GT',
            'is_default' => true,
        ]);

        // Cannot request withdrawal exceeding available balance
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/affiliate/withdrawals', [
                'amount' => 50000.00,
            ])
            ->assertStatus(422);

        // Valid withdrawal request
        $wthRes = $this->actingAs($user, 'api')
            ->postJson('/api/v1/affiliate/withdrawals', [
                'amount' => 25000.00,
                'payout_method_id' => $method->id,
            ]);

        $wthRes->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.amount', '25000.00')
            ->assertJsonPath('data.status', 'pending');

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/affiliate/withdrawals')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonCount(1, 'data.items');
    }
}
