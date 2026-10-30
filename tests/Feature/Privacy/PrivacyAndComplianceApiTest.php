<?php

namespace Tests\Feature\Privacy;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Address\Address;
use App\Models\Order\Order;
use App\Models\User;
use App\Models\User\Profile;
use App\Models\User\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrivacyAndComplianceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('p', 32)),
            'jwt.secret' => 'test-secret-with-at-least-thirty-two-characters',
        ]);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_guest_and_user_can_get_and_update_privacy_consents(): void
    {
        // 1. Guest gets defaults
        $response = $this->getJson('/api/v1/privacy/consents')
            ->assertOk()
            ->assertJsonPath('data.essential', true)
            ->assertJsonPath('data.analytics', false)
            ->assertJsonPath('data.marketing', false);

        // 2. Guest updates consents
        $guestResponse = $this->withHeader('X-Guest-Token', 'guest-token-123')
            ->putJson('/api/v1/privacy/consents', [
                'analytics' => true,
                'marketing' => false,
                'third_party_sharing' => false,
                'source' => 'web_banner',
            ])
            ->assertOk()
            ->assertJsonPath('data.analytics', true)
            ->assertJsonPath('data.marketing', false);

        $this->assertDatabaseHas('privacy_consents', [
            'guest_token' => 'guest-token-123',
            'analytics' => true,
            'source' => 'web_banner',
        ]);

        // 3. Authenticated user updates consents & preference synchronizes
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        UserPreference::create(['user_id' => $user->id, 'marketing_emails' => false]);

        $userResponse = $this->actingAs($user, 'api')
            ->putJson('/api/v1/privacy/consents', [
                'analytics' => true,
                'marketing' => true,
                'third_party_sharing' => true,
                'personalized_ads' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.marketing', true)
            ->assertJsonPath('data.third_party_sharing', true);

        $this->assertDatabaseHas('privacy_consents', [
            'user_id' => $user->id,
            'marketing' => true,
            'third_party_sharing' => true,
        ]);

        $this->assertTrue($user->preference->fresh()->marketing_emails);
    }

    public function test_user_can_request_and_retrieve_gdpr_data_export(): void
    {
        $user = User::factory()->create([
            'username' => 'export_user',
            'email' => 'export@example.test',
            'password' => 'secret12345',
            'role' => 'user',
            'status' => 'active',
        ]);
        Profile::create([
            'user_id' => $user->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'phone' => '+2348011112222',
        ]);
        Address::create([
            'user_id' => $user->id,
            'recipient_name' => 'John Doe',
            'phone' => '+2348011112222',
            'line1' => '10 Victoria Island',
            'city' => 'Lagos',
            'state' => 'Lagos',
            'country' => 'NG',
            'type' => 'delivery',
        ]);
        Order::create([
            'user_id' => $user->id,
            'number' => 'ORD-EXPORT-1',
            'currency' => 'NGN',
            'subtotal' => '5000.00',
            'discount_total' => '0.00',
            'credit_total' => '0.00',
            'shipping_total' => '500.00',
            'grand_total' => '5500.00',
            'total_amount' => '5500.00',
            'status' => 'delivered',
            'payment_status' => 'paid',
            'fulfilment_status' => 'delivered',
            'payment_method' => 'card',
            'contact_email' => $user->email,
            'delivery_method' => 'standard',
            'shipping_address' => [
                'recipient_name' => 'John Doe',
                'phone' => '+2348011112222',
                'line1' => '10 Victoria Island',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'country' => 'NG',
            ],
        ]);

        // Wrong password rejected
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/account/data-export', ['password' => 'wrongpass'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        // Correct password creates export
        $response = $this->actingAs($user, 'api')
            ->postJson('/api/v1/account/data-export', ['password' => 'secret12345'])
            ->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.data.user.email', 'export@example.test')
            ->assertJsonPath('data.data.profile.first_name', 'John')
            ->assertJsonCount(1, 'data.data.addresses')
            ->assertJsonCount(1, 'data.data.orders');

        $exportId = $response->json('data.export_id');

        $this->assertDatabaseHas('account_data_exports', [
            'id' => $exportId,
            'user_id' => $user->id,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'account.data_exported',
            'actor_id' => $user->id,
        ]);

        // User can view export
        $this->actingAs($user, 'api')
            ->getJson("/api/v1/account/data-export/{$exportId}")
            ->assertOk()
            ->assertJsonPath('data.export_id', $exportId);

        // Stranger cannot view export
        $stranger = User::factory()->create(['role' => 'user']);
        $this->actingAs($stranger, 'api')
            ->getJson("/api/v1/account/data-export/{$exportId}")
            ->assertForbidden();
    }

    public function test_user_can_request_and_cancel_account_deletion(): void
    {
        $user = User::factory()->create([
            'password' => 'mypassword123',
            'role' => 'user',
            'status' => 'active',
        ]);

        // Wrong password rejected
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/account/deletion-request', [
                'password' => 'wrong',
                'confirm' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');

        // Valid request schedules deletion
        $response = $this->actingAs($user, 'api')
            ->postJson('/api/v1/account/deletion-request', [
                'password' => 'mypassword123',
                'reason' => 'Moving to another service.',
                'confirm' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.grace_period_days', 14);

        $user->refresh();
        $this->assertNotNull($user->deletion_scheduled_for);
        $this->assertSame('Moving to another service.', $user->deactivation_reason);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'account.deletion_requested',
            'actor_id' => $user->id,
        ]);

        // User cancels deletion
        $this->actingAs($user, 'api')
            ->deleteJson('/api/v1/account/deletion-request')
            ->assertOk();

        $user->refresh();
        $this->assertNull($user->deletion_scheduled_for);
        $this->assertNull($user->deactivation_reason);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'account.deletion_cancelled',
            'actor_id' => $user->id,
        ]);
    }

    public function test_last_super_admin_cannot_schedule_deletion(): void
    {
        $admin = User::factory()->create([
            'role' => 'admin',
            'password' => 'adminpassword',
            'status' => 'active',
        ]);

        $roleId = DB::table('roles')->insertGetId([
            'name' => 'super_admin',
            'display_name' => 'Super Administrator',
            'is_system' => true,
            'is_super_admin' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_user')->insert([
            'user_id' => $admin->id,
            'role_id' => $roleId,
        ]);

        $this->actingAs($admin, 'api')
            ->postJson('/api/v1/account/deletion-request', [
                'password' => 'adminpassword',
                'confirm' => true,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('account');
    }

    public function test_age_verification_checks_age_and_records_status(): void
    {
        // 1. Underage birth date rejected
        $underageDate = now()->subYears(16)->format('Y-m-d');
        $this->postJson('/api/v1/age-verification', [
            'birth_date' => $underageDate,
        ])->assertStatus(403)
            ->assertJsonPath('code', 'AGE_VERIFICATION_FAILED');

        // 2. Adult birth date accepted for authenticated user
        $adultDate = now()->subYears(22)->format('Y-m-d');
        $user = User::factory()->create(['role' => 'user', 'status' => 'active']);
        Profile::create(['user_id' => $user->id]);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/age-verification', [
                'birth_date' => $adultDate,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_age_verified', true);

        $user->refresh();
        $this->assertTrue($user->is_age_verified);
        $this->assertNotNull($user->age_verified_at);
        $this->assertSame($adultDate, $user->profile->birthday?->format('Y-m-d'));

        // 3. Guest confirmed accepted
        $this->withHeader('X-Guest-Token', 'guest-age-123')
            ->postJson('/api/v1/age-verification', [
                'confirmed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.is_age_verified', true);
    }
}
