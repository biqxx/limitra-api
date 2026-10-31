<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Affiliate\Affiliate;
use App\Models\Affiliate\AffiliateApplication;
use App\Models\Affiliate\AffiliatePayoutMethod;
use App\Models\Affiliate\AffiliateWithdrawal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AffiliateOperationsAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_admin_can_review_and_approve_affiliate_application(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $applicant = User::factory()->create(['role' => 'user', 'username' => 'newpartner']);

        $application = AffiliateApplication::create([
            'user_id' => $applicant->id,
            'status' => 'pending',
            'promotion_channels' => ['blog', 'tiktok'],
            'website_url' => 'https://newpartner.com',
            'notes' => 'Looking to partner up',
        ]);

        // Staff/Admin list applications
        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/affiliate-applications?status=pending')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonCount(1, 'data.items');

        // Admin approves application
        $approveRes = $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/affiliate-applications/{$application->id}/approve", [
                'admin_notes' => 'Approved after verifying social links.',
            ]);

        $approveRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.application.status', 'approved');

        $this->assertDatabaseHas('affiliates', [
            'user_id' => $applicant->id,
            'status' => 'active',
        ]);

        $this->assertEquals('affiliate', $applicant->fresh()->role);
        $this->assertTrue($applicant->roles()->where('name', 'affiliate')->exists());

        $this->assertDatabaseHas('audit_events', [
            'action' => 'affiliate_application.approved',
            'actor_id' => $admin->id,
            'subject_id' => $application->id,
        ]);
    }

    public function test_admin_can_review_and_mark_paid_withdrawal(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $affUser = User::factory()->create(['role' => 'affiliate']);

        $affiliate = Affiliate::create([
            'user_id' => $affUser->id,
            'slug' => 'aff-wth-ops',
            'code' => 'WTHOPS01',
            'status' => 'active',
            'total_earnings' => 100000.00,
            'total_paid' => 20000.00,
        ]);

        $payoutMethod = AffiliatePayoutMethod::create([
            'affiliate_id' => $affiliate->id,
            'bank_name' => 'Kuda Bank',
            'account_number' => '1100223344',
            'account_name' => 'Affiliate Kuda',
            'is_default' => true,
        ]);

        $withdrawal = AffiliateWithdrawal::create([
            'affiliate_id' => $affiliate->id,
            'payout_method_id' => $payoutMethod->id,
            'amount' => 30000.00,
            'currency' => 'NGN',
            'status' => 'pending',
            'reference' => 'WTH-TEST-001',
        ]);

        // List withdrawals
        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/withdrawals?status=pending')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        // Approve withdrawal
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/withdrawals/{$withdrawal->id}/approve", [
                'admin_notes' => 'Passed security check.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        // Mark paid by a second administrator (enforcing separation of duties)
        $payerAdmin = User::factory()->create(['role' => 'admin']);
        $paidRes = $this->actingAs($payerAdmin, 'api')
            ->postJson("/api/v1/admin/withdrawals/{$withdrawal->id}/mark-paid", [
                'provider_reference' => 'TRF-PAYSTACK-998877',
            ]);

        $paidRes->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.withdrawal.status', 'paid');

        // Total paid on affiliate model was atomically incremented
        $this->assertEquals('50000.00', $affiliate->fresh()->total_paid);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'affiliate_withdrawal.paid',
            'actor_id' => $payerAdmin->id,
            'subject_id' => $withdrawal->id,
        ]);
    }
}
