<?php

namespace Tests\Feature\Admin;

use App\Http\Middleware\TrackAnalytics;
use App\Models\AI\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AIConversationAdminApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_admin_can_list_and_filter_ai_conversations(): void
    {
        $admin = $this->createAdminUser(['ai.read', 'ai.manage']);
        $customer = User::factory()->create([
            'username' => 'alice_ai',
            'email' => 'alice.ai@example.test',
            'role' => 'user',
        ]);

        $c1 = Conversation::create([
            'user_id' => $customer->id,
            'platform' => 'web',
            'status' => 'open',
            'last_message_at' => now(),
        ]);
        $c1->messages()->create(['role' => 'user', 'content' => 'Hello AI']);

        $c2 = Conversation::create([
            'user_id' => $customer->id,
            'platform' => 'whatsapp',
            'status' => 'closed',
            'last_message_at' => now()->subDay(),
            'closed_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/ai/conversations?channel=web')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $c1->id)
            ->assertJsonPath('data.items.0.channel', 'web')
            ->assertJsonPath('data.items.0.user.email', 'alice.ai@example.test');

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/ai/conversations?status=closed')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $c2->id);

        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/ai/conversations?search=alice')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2);
    }

    public function test_admin_can_view_conversation_details_with_messages(): void
    {
        $admin = $this->createAdminUser(['ai.read']);
        $customer = User::factory()->create(['username' => 'bob_ai', 'role' => 'user']);

        $conversation = Conversation::create([
            'user_id' => $customer->id,
            'platform' => 'web',
            'status' => 'open',
            'last_message_at' => now(),
        ]);
        $conversation->messages()->create(['role' => 'user', 'content' => 'What is the return policy?']);
        $conversation->messages()->create(['role' => 'assistant', 'content' => 'Items can be returned within 14 days.']);

        $response = $this->actingAs($admin, 'api')
            ->getJson("/api/v1/admin/ai/conversations/{$conversation->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->id)
            ->assertJsonPath('data.user.username', 'bob_ai')
            ->assertJsonCount(2, 'data.messages');
    }

    public function test_admin_can_update_conversation_status_and_add_notes(): void
    {
        $admin = $this->createAdminUser(['ai.read', 'ai.manage']);
        $customer = User::factory()->create(['role' => 'user']);

        $conversation = Conversation::create([
            'user_id' => $customer->id,
            'platform' => 'web',
            'status' => 'open',
        ]);

        $response = $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/ai/conversations/{$conversation->id}", [
                'status' => 'closed',
                'notes' => 'Resolved issue via chat and closed.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->assertNotNull($response->json('data.closed_at'));

        $conversation->refresh();
        $this->assertSame('closed', $conversation->status);
        $this->assertNotNull($conversation->closed_at);
        $this->assertSame('Resolved issue via chat and closed.', $conversation->context['admin_notes'][0]['note']);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'ai_conversation.status_updated',
            'subject_type' => Conversation::class,
            'subject_id' => $conversation->id,
            'actor_id' => $admin->id,
        ]);
    }

    public function test_regular_user_cannot_access_admin_ai_conversations(): void
    {
        $regularUser = User::factory()->create(['role' => 'user']);

        $this->actingAs($regularUser, 'api')
            ->getJson('/api/v1/admin/ai/conversations')
            ->assertForbidden();
    }

    private function createAdminUser(array $permissions): User
    {
        $admin = User::factory()->create(['role' => 'admin', 'status' => 'active']);

        $roleId = DB::table('roles')->where('name', 'admin')->value('id');
        if (! $roleId) {
            $roleId = DB::table('roles')->insertGetId([
                'name' => 'admin',
                'display_name' => 'Administrator',
                'is_system' => true,
                'is_super_admin' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        DB::table('role_user')->insertOrIgnore([
            'user_id' => $admin->id,
            'role_id' => $roleId,
        ]);

        foreach ($permissions as $perm) {
            $permId = DB::table('permissions')->where('name', $perm)->value('id');
            if (! $permId) {
                $permId = DB::table('permissions')->insertGetId([
                    'name' => $perm,
                    'domain' => 'ai',
                    'description' => $perm,
                    'is_system' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            DB::table('permission_role')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permId,
            ]);
        }

        return $admin;
    }
}
