<?php

namespace Tests\Feature;

use App\Enums\UserStatus;
use App\Http\Middleware\TrackAnalytics;
use App\Models\Address\Address;
use App\Models\Order\Order;
use App\Models\Payment\Account;
use App\Models\Payment\SavedCard;
use App\Models\Payment\WalletTransaction;
use App\Models\Referral\CustomerReferralCode;
use App\Models\Social\SocialAccount;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Models\User\Profile;
use App\Services\Auth\AuthSessionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class UserDeactivationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('d', 32)),
            'jwt.secret' => 'test-secret-with-at-least-thirty-two-characters',
        ]);
        $this->withoutMiddleware(TrackAnalytics::class);
        Storage::fake('public');
    }

    public function test_super_administrator_deactivates_and_anonymizes_without_deleting_history(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->affiliate()->create([
            'username' => 'former_customer',
            'email' => 'former@example.test',
            'password' => 'old-password',
        ]);
        Storage::disk('public')->put('avatars/former.jpg', 'avatar');
        $profile = Profile::query()->create([
            'user_id' => $user->id,
            'first_name' => 'Former',
            'last_name' => 'Customer',
            'avatar' => 'avatars/former.jpg',
            'phone' => '+2348000000000',
            'birthday' => '1990-01-01',
            'subscribe_to_newsletter' => true,
        ]);
        $address = Address::query()->create([
            'user_id' => $user->id,
            'type' => 'delivery',
            'recipient_name' => 'Former Customer',
            'phone' => '+2348000000000',
            'line1' => '12 Private Street',
            'city' => 'Lagos',
            'state' => 'Lagos',
            'country' => 'NG',
            'is_default' => true,
        ]);
        $order = Order::query()->create([
            'user_id' => $user->id,
            'number' => 'LMT-DEACTIVATE-1',
            'currency' => 'NGN',
            'subtotal' => '1000.00',
            'discount_total' => '0.00',
            'credit_total' => '0.00',
            'shipping_total' => '100.00',
            'grand_total' => '1100.00',
            'total_amount' => '1100.00',
            'status' => 'delivered',
            'payment_status' => 'paid',
            'fulfilment_status' => 'delivered',
            'payment_method' => 'card',
            'contact_email' => $user->email,
            'notes' => 'Call the customer on arrival.',
            'delivery_method' => 'standard',
            'shipping_address_id' => $address->id,
            'shipping_address' => $address->only([
                'recipient_name', 'phone', 'line1', 'line2', 'city', 'landmark', 'state', 'country', 'postal_code',
            ]),
        ]);
        $account = Account::query()->create(['user_id' => $user->id, 'currency' => 'NGN']);
        $walletTransaction = WalletTransaction::query()->create([
            'reference' => (string) Str::uuid(),
            'account_id' => $account->id,
            'type' => 'deposit',
            'balance_type' => 'cash',
            'direction' => 'credit',
            'amount_minor' => 250000,
            'balance_after_minor' => 250000,
            'currency' => 'NGN',
            'unique_key' => 'deactivation-test-wallet',
            'description' => 'Historical deposit',
        ]);
        $ticket = SupportTicket::query()->create([
            'number' => 'SUP-DEACTIVATE-1',
            'user_id' => $user->id,
            'order_id' => $order->id,
            'category' => 'order',
            'subject' => 'Historical support issue',
            'status' => 'closed',
            'priority' => 'normal',
            'contact_name' => 'Former Customer',
            'contact_email' => $user->email,
            'first_response_due_at' => now()->addHour(),
            'resolution_due_at' => now()->addDay(),
            'closed_at' => now(),
            'last_message_at' => now(),
        ]);
        $message = $ticket->messages()->create([
            'sender_id' => $user->id,
            'sender_type' => 'customer',
            'message' => 'Preserve this historical conversation.',
        ]);
        $savedCard = SavedCard::query()->create([
            'user_id' => $user->id,
            'authorization_code' => 'AUTH_sensitive',
            'signature' => 'card-signature-deactivate',
            'brand' => 'visa',
            'last4' => '4081',
            'expiry_month' => 12,
            'expiry_year' => 2030,
            'reusable' => true,
        ]);
        $socialAccount = SocialAccount::query()->create([
            'user_id' => $user->id,
            'platform' => 'whatsapp',
            'platform_sender_id' => '+2348000000000',
            'username' => 'Former Customer',
            'metadata' => ['phone' => '+2348000000000'],
        ]);
        $referralCode = CustomerReferralCode::query()->create([
            'user_id' => $user->id,
            'code' => 'FORMERREF',
        ]);
        $session = $user->authSessions()->create([
            'device_name' => 'Personal phone',
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Private Browser',
            'last_used_at' => now(),
            'expires_at' => now()->addHour(),
        ]);
        app(AuthSessionManager::class)->remember($session);

        $this->actingAs($admin, 'api')->deleteJson("/api/v1/admin/users/{$user->id}", [
            'confirmation' => true,
            'reason' => 'Account closure approved.',
        ])->assertNoContent();

        $deactivatedUser = User::withTrashed()->findOrFail($user->id);
        $this->assertSoftDeleted($deactivatedUser);
        $this->assertSame(UserStatus::Deactivated, $deactivatedUser->status);
        $this->assertSame("deactivated_{$user->id}", $deactivatedUser->username);
        $this->assertSame("deactivated+{$user->id}@users.invalid", $deactivatedUser->email);
        $this->assertFalse(Hash::check('old-password', $deactivatedUser->password));
        $this->assertNotNull($deactivatedUser->deactivated_at);
        $this->assertSame($admin->id, $deactivatedUser->deactivated_by);
        $this->assertSame('Account closure approved.', $deactivatedUser->deactivation_reason);
        $this->assertDatabaseMissing('role_user', ['user_id' => $user->id]);

        $this->assertSame('Deactivated', $profile->fresh()->first_name);
        $this->assertNull($profile->fresh()->phone);
        $this->assertNull($profile->fresh()->avatar);
        $this->assertSame('Redacted', $address->fresh()->line1);
        $this->assertSame('redacted', $address->fresh()->phone);
        Storage::disk('public')->assertMissing('avatars/former.jpg');

        $this->assertModelExists($order);
        $this->assertSame('1100.00', $order->fresh()->grand_total);
        $this->assertSame($deactivatedUser->email, $order->fresh()->contact_email);
        $this->assertSame('Deactivated User', $order->fresh()->shipping_address['recipient_name']);
        $this->assertModelExists($account);
        $this->assertModelExists($walletTransaction);
        $this->assertSame(250000, $walletTransaction->fresh()->amount_minor);
        $this->assertModelExists($ticket);
        $this->assertModelExists($message);
        $this->assertSame('Deactivated User', $ticket->fresh()->contact_name);
        $this->assertModelMissing($savedCard);

        $this->assertNull($socialAccount->fresh()->user_id);
        $this->assertNull($socialAccount->fresh()->metadata);
        $this->assertFalse($referralCode->fresh()->active);
        $this->assertNotNull($session->fresh()->revoked_at);
        $this->assertNull($session->fresh()->ip_address);
        $this->assertFalse(app(AuthSessionManager::class)->isActive($session->id, $user->id));

        $this->assertDatabaseHas('audit_events', [
            'action' => 'user.deactivated',
            'actor_id' => $admin->id,
            'subject_id' => $user->id,
            'reason' => 'Account closure approved.',
        ]);

        $this->actingAs($deactivatedUser, 'api')->getJson('/api/v1/me')
            ->assertForbidden()
            ->assertJsonPath('code', 'ACCOUNT_DEACTIVATED');

        $replacement = User::factory()->create([
            'username' => 'former_customer',
            'email' => 'former@example.test',
        ]);
        $this->assertModelExists($replacement);
    }

    public function test_deactivation_requires_explicit_confirmation_and_reason(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($admin, 'api')
            ->deleteJson("/api/v1/admin/users/{$user->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['confirmation', 'reason']);

        $this->assertModelExists($user);
    }
}
