<?php

namespace Tests\Feature;

use App\Http\Middleware\TrackAnalytics;
use App\Models\Order\Order;
use App\Models\Support\SupportTicket;
use App\Models\User;
use App\Services\Settings\BusinessSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupportTicketApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_guest_can_create_ticket_with_contact_details_and_initial_message(): void
    {
        $response = $this->postJson('/api/v1/support/tickets', [
            'category' => 'technical',
            'subject' => 'Checkout button does not respond',
            'message' => 'The checkout button does not respond when I click it.',
            'contact_name' => 'Guest Customer',
            'contact_email' => 'GUEST@EXAMPLE.COM',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.priority', 'normal')
            ->assertJsonPath('data.contact.email', 'guest@example.com')
            ->assertJsonPath('data.messages.0.sender_type', 'customer')
            ->assertJsonPath('data.messages.0.message', 'The checkout button does not respond when I click it.');

        $ticket = SupportTicket::firstOrFail();
        $this->assertStringStartsWith('SUP-', $ticket->number);
        $this->assertNull($ticket->user_id);
        $this->assertNotNull($ticket->first_response_due_at);
        $this->assertNotNull($ticket->resolution_due_at);
        $this->assertDatabaseHas('support_ticket_messages', [
            'support_ticket_id' => $ticket->id,
            'sender_type' => 'customer',
        ]);
    }

    public function test_guest_contact_fields_and_configured_category_are_required(): void
    {
        $this->postJson('/api/v1/support/tickets', [
            'category' => 'unknown',
            'subject' => 'Need assistance',
            'message' => 'Please help me resolve this issue.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['category', 'contact_name', 'contact_email']);
    }

    public function test_guest_ticket_creation_can_be_disabled_by_admin_setting(): void
    {
        $admin = $this->user('support-admin@example.com', 'admin');
        app(BusinessSettingsService::class)->update([
            ['key' => 'support.guest_tickets_enabled', 'value' => false],
        ], $admin);

        $this->postJson('/api/v1/support/tickets', [
            'category' => 'account',
            'subject' => 'Cannot access my account',
            'message' => 'I cannot access my account after changing my password.',
            'contact_name' => 'Guest Customer',
            'contact_email' => 'guest-disabled@example.com',
        ])->assertUnprocessable()->assertJsonValidationErrors('contact_email');
    }

    public function test_authenticated_customer_can_link_only_their_order(): void
    {
        $customer = $this->user('ticket-owner@example.com');
        $other = $this->user('other-order-owner@example.com');
        $order = $this->order($customer, 'ORD-SUPPORT-001');
        $otherOrder = $this->order($other, 'ORD-SUPPORT-002');

        $this->actingAs($customer, 'api')->postJson('/api/v1/support/tickets', [
            'category' => 'order',
            'subject' => 'Question about my delivery',
            'message' => 'Please confirm when my order will be delivered.',
            'order_id' => $order->id,
        ])->assertCreated()
            ->assertJsonPath('data.order.id', $order->id)
            ->assertJsonPath('data.contact.email', $customer->email);

        $this->actingAs($customer, 'api')->postJson('/api/v1/support/tickets', [
            'category' => 'order',
            'subject' => 'Question about another delivery',
            'message' => 'Please confirm when this order will be delivered.',
            'order_id' => $otherOrder->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('order_id');
    }

    public function test_guest_can_link_order_only_when_contact_email_matches(): void
    {
        $customer = $this->user('guest-order@example.com');
        $order = $this->order($customer, 'ORD-SUPPORT-003');

        $payload = [
            'category' => 'delivery',
            'subject' => 'Guest delivery question',
            'message' => 'I need an update about this delivery.',
            'order_id' => $order->id,
            'contact_name' => 'Guest Customer',
            'contact_email' => 'wrong@example.com',
        ];
        $this->postJson('/api/v1/support/tickets', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('order_id');

        $payload['contact_email'] = $order->contact_email;
        $this->postJson('/api/v1/support/tickets', $payload)
            ->assertCreated()->assertJsonPath('data.order.id', $order->id);
    }

    public function test_customer_lists_and_views_only_own_tickets_while_staff_can_view_any_ticket(): void
    {
        $owner = $this->user('ticket-list-owner@example.com');
        $other = $this->user('ticket-list-other@example.com');
        $staff = $this->user('ticket-list-staff@example.com', 'staff');
        $ticket = $this->createTicket($owner, 'Owner ticket');
        $this->createTicket($other, 'Other ticket');

        $this->actingAs($owner, 'api')->getJson('/api/v1/support/tickets?status=open')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $ticket->id)
            ->assertJsonPath('data.items.0.message_count', 1);

        $this->actingAs($other, 'api')->getJson("/api/v1/support/tickets/{$ticket->id}")
            ->assertForbidden();

        $this->actingAs($staff, 'api')->getJson("/api/v1/support/tickets/{$ticket->id}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.message', 'This is the initial support message.');
    }

    private function createTicket(User $user, string $subject): SupportTicket
    {
        $this->actingAs($user, 'api')->postJson('/api/v1/support/tickets', [
            'category' => 'account',
            'subject' => $subject,
            'message' => 'This is the initial support message.',
        ])->assertCreated();

        return SupportTicket::query()->latest('id')->firstOrFail();
    }

    private function user(string $email, string $role = 'user'): User
    {
        return User::query()->create([
            'username' => str($email)->before('@').'-'.fake()->unique()->numberBetween(100, 999),
            'email' => $email,
            'password' => 'password',
            'role' => $role,
            'email_verified_at' => now(),
        ]);
    }

    private function order(User $user, string $number): Order
    {
        return Order::query()->create([
            'user_id' => $user->id,
            'number' => $number,
            'currency' => 'NGN',
            'subtotal' => '1000.00',
            'discount_total' => '0.00',
            'credit_total' => '0.00',
            'shipping_total' => '0.00',
            'grand_total' => '1000.00',
            'total_amount' => '1000.00',
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'fulfilment_status' => 'processing',
            'payment_method' => 'card',
            'contact_email' => $user->email,
            'delivery_method' => 'standard',
            'shipping_address' => [
                'full_name' => 'Support Customer',
                'address_line_1' => '1 Test Street',
                'city' => 'Lagos',
                'state' => 'Lagos',
                'country' => 'NG',
            ],
        ]);
    }
}
