<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Affiliate\AffiliateApplication;
use App\Models\Order\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardAndReportsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_admin_dashboard_metrics_and_pending_counts(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $buyer = User::factory()->create(['role' => 'user']);

        Order::create([
            'user_id' => $buyer->id,
            'number' => 'ORD-DB-001',
            'currency' => 'NGN',
            'subtotal' => 200.00,
            'grand_total' => 200.00,
            'total_amount' => 200.00,
            'status' => 'delivered',
            'payment_status' => 'paid',
            'payment_method' => 'card',
            'contact_email' => $buyer->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['line1' => '123 Test Rd'],
        ]);

        AffiliateApplication::create([
            'user_id' => $buyer->id,
            'status' => 'pending',
        ]);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/dashboard')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.metrics.total_orders', 1)
            ->assertJsonPath('data.metrics.total_revenue', '200.00')
            ->assertJsonPath('data.pending_actions.affiliate_applications', 1);
    }

    public function test_admin_health_status(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/health')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'healthy')
            ->assertJsonPath('data.checks.database', 'healthy');
    }

    public function test_admin_reports_generation_and_listing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        // Generate report
        $repRes = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/reports', [
                'type' => 'orders',
                'format' => 'csv',
            ]);

        $repRes->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.type', 'orders')
            ->assertJsonPath('data.status', 'completed');

        $reportId = $repRes->json('data.id');

        // List reports
        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/reports')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        // Show report
        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/admin/reports/{$reportId}")
            ->assertOk()
            ->assertJsonPath('data.report.id', $reportId)
            ->assertJsonStructure(['data' => ['report', 'download_url']]);
    }
}
