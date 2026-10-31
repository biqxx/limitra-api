<?php

namespace Tests\Feature\Admin;

use App\AI\AgentService;
use App\AI\Contracts\AgentDriver;
use App\AI\Data\AgentResponse;
use App\Http\Middleware\TrackAnalytics;
use App\Jobs\AI\ProcessAIMessageJob;
use App\Models\AI\Conversation;
use App\Models\AI\ConversationMessage;
use App\Models\AI\KnowledgeDocument;
use App\Models\Settings\BusinessSetting;
use App\Models\User;
use App\Services\AI\AttachmentSecurityScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AIAdminAndVisionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
        Storage::fake('local');
    }

    public function test_attachment_security_scanner_accepts_valid_image(): void
    {
        $scanner = app(AttachmentSecurityScanner::class);

        $validImage = UploadedFile::fake()->image('test_product.png', 800, 600);
        $scanned = $scanner->scanAndStore($validImage, 999);

        $this->assertSame('image/png', $scanned['mime_type']);
        $this->assertSame(800, $scanned['width']);
        $this->assertSame(600, $scanned['height']);
        $this->assertNotEmpty($scanned['fingerprint']);
        $this->assertNotEmpty($scanned['path']);
        Storage::disk('local')->assertExists($scanned['path']);

        // Check vision content conversion
        $visionContent = $scanner->toVisionContent($scanned);
        $this->assertSame('image_url', $visionContent['type']);
        $this->assertStringStartsWith('data:image/png;base64,', $visionContent['image_url']['url']);
    }

    public function test_attachment_security_scanner_rejects_non_image_extension(): void
    {
        $scanner = app(AttachmentSecurityScanner::class);

        $fakePhp = UploadedFile::fake()->create('malicious.php', 100, 'text/php');
        $this->expectException(ValidationException::class);
        $scanner->scanAndStore($fakePhp, 999);
    }

    public function test_customer_can_send_image_attachment_and_receive_ai_vision_response(): void
    {
        $driver = new class implements AgentDriver
        {
            public int $calls = 0;

            public array $lastMessages = [];

            public function complete(
                array $messages,
                array $toolDefinitions,
                string $systemContext = '',
                bool $highReasoning = false,
            ): AgentResponse {
                $this->calls++;
                $this->lastMessages = $messages;

                return new AgentResponse('I can see the image of your stylish jacket!');
            }
        };

        $this->app->instance(AgentService::class, new AgentService($driver, []));

        $customer = User::factory()->create(['role' => 'user']);
        $conversation = Conversation::create([
            'user_id' => $customer->id,
            'platform' => 'web',
            'status' => 'open',
            'last_message_at' => now(),
        ]);

        $image = UploadedFile::fake()->image('jacket.jpg', 600, 600);

        $response = $this->actingAs($customer, 'api')
            ->post("/api/v1/ai/conversations/{$conversation->id}/messages", [
                'text' => 'Can you identify this jacket?',
                'client_message_id' => 'msg-with-attachment-1',
                'attachments' => [$image],
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'assistant')
            ->assertJsonPath('data.content', 'I can see the image of your stylish jacket!');

        $this->assertSame(1, $driver->calls);
        $this->assertNotEmpty($driver->lastMessages);

        // Verify attachment was recorded on user message
        $userMsg = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('role', 'user')
            ->first();

        $this->assertNotNull($userMsg);
        $this->assertIsArray($userMsg->attachments);
        $this->assertCount(1, $userMsg->attachments);
        $this->assertArrayHasKey('fingerprint', $userMsg->attachments[0]);
        $this->assertArrayHasKey('download_url', $userMsg->attachments[0]);
    }

    public function test_customer_can_download_private_attachment_and_other_user_is_forbidden(): void
    {
        $customer = User::factory()->create(['role' => 'user']);
        $otherCustomer = User::factory()->create(['role' => 'user']);

        $conversation = Conversation::create([
            'user_id' => $customer->id,
            'platform' => 'web',
            'status' => 'open',
        ]);

        $scanner = app(AttachmentSecurityScanner::class);
        $scanned = $scanner->scanAndStore(UploadedFile::fake()->image('sample.jpg', 400, 400), $conversation->id);

        $conversation->messages()->create([
            'role' => 'user',
            'content' => 'Look at this photo',
            'metadata' => ['attachments' => [$scanned]],
        ]);

        $attachmentId = $scanned['id'];

        // Owner can download
        $this->actingAs($customer, 'api')
            ->getJson("/api/v1/ai/conversations/{$conversation->id}/attachments/{$attachmentId}")
            ->assertOk();

        // Other customer is forbidden
        $this->actingAs($otherCustomer, 'api')
            ->getJson("/api/v1/ai/conversations/{$conversation->id}/attachments/{$attachmentId}")
            ->assertForbidden();
    }

    public function test_handoff_is_strictly_idempotent(): void
    {
        $customer = User::factory()->create(['role' => 'user']);
        $conversation = Conversation::create([
            'user_id' => $customer->id,
            'platform' => 'web',
            'status' => 'open',
        ]);
        $conversation->messages()->create(['role' => 'user', 'content' => 'I want to speak with a human.']);

        $res1 = $this->actingAs($customer, 'api')
            ->postJson("/api/v1/ai/conversations/{$conversation->id}/handoff", [
                'reason' => 'Customer requested human agent.',
            ])
            ->assertOk();

        $ticket1 = $res1->json('data.ticket.id');
        $this->assertNotEmpty($ticket1);

        $conversation->refresh();
        $this->assertSame('handed_off', $conversation->status);

        // Second handoff call should return the existing ticket
        $res2 = $this->actingAs($customer, 'api')
            ->postJson("/api/v1/ai/conversations/{$conversation->id}/handoff", [
                'reason' => 'Customer requested human agent again.',
            ])
            ->assertOk();

        $ticket2 = $res2->json('data.ticket.id');
        $this->assertSame($ticket1, $ticket2);
    }

    public function test_async_message_mode_dispatches_job_and_returns_accepted(): void
    {
        Queue::fake();

        $customer = User::factory()->create(['role' => 'user']);
        $conversation = Conversation::create([
            'user_id' => $customer->id,
            'platform' => 'web',
            'status' => 'open',
        ]);

        $this->actingAs($customer, 'api')
            ->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", [
                'text' => 'Process this asynchronously please.',
                'client_message_id' => 'async-msg-1',
                'async' => true,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'processing')
            ->assertJsonPath('data.client_message_id', 'async-msg-1');

        Queue::assertPushed(ProcessAIMessageJob::class, function (ProcessAIMessageJob $job) use ($conversation): bool {
            return $job->conversationId === $conversation->id;
        });
    }

    public function test_admin_can_view_metrics_and_retrieve_safe_settings(): void
    {
        $admin = $this->createAdminUser(['ai.read', 'ai.settings.manage']);

        $c1 = Conversation::create([
            'user_id' => $admin->id,
            'platform' => 'web',
            'status' => 'open',
        ]);
        $c1->messages()->create([
            'role' => 'assistant',
            'content' => 'Hi',
            'latency_ms' => 320,
            'tokens' => ['total' => 45],
        ]);

        $c2 = Conversation::create([
            'user_id' => $admin->id,
            'platform' => 'whatsapp',
            'status' => 'handed_off',
        ]);
        $c2->messages()->create([
            'role' => 'assistant',
            'content' => 'Connecting to agent',
            'latency_ms' => 180,
            'tokens' => ['total' => 25],
        ]);

        // Metrics
        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/ai/metrics')
            ->assertOk()
            ->assertJsonPath('data.total_conversations', 2)
            ->assertJsonPath('data.total_messages', 2)
            ->assertJsonPath('data.total_tokens', 70)
            ->assertJsonPath('data.status_breakdown.handed_off', 1);

        $this->assertEquals(50.0, (float) $this->actingAs($admin, 'api')->getJson('/api/v1/admin/ai/metrics')->json('data.handoff_rate'));

        // Safe Settings (must not expose secret API keys)
        $settingsResponse = $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/ai/settings')
            ->assertOk();

        $data = $settingsResponse->json('data');
        $this->assertArrayHasKey('driver', $data);
        $this->assertArrayHasKey('primary_model', $data);
        $this->assertArrayHasKey('enabled_tools', $data);
        $this->assertArrayNotHasKey('api_key', $data);
        $this->assertArrayNotHasKey('secret', $data);
    }

    public function test_admin_can_update_ai_settings_and_audit_event_is_recorded(): void
    {
        $admin = $this->createAdminUser(['ai.read', 'ai.settings.manage']);

        // Seed setting if not present
        BusinessSetting::updateOrCreate(
            ['key' => 'ai.primary_model'],
            [
                'group' => 'ai',
                'type' => 'string',
                'value' => 'inclusionai/ling-3.0-flash',
                'version' => 1,
            ]
        );

        $this->actingAs($admin, 'api')
            ->patchJson('/api/v1/admin/ai/settings', [
                'primary_model' => 'anthropic/claude-3-haiku',
            ])
            ->assertOk()
            ->assertJsonPath('data.primary_model', 'anthropic/claude-3-haiku');

        $this->assertDatabaseHas('business_settings', [
            'key' => 'ai.primary_model',
            'value' => json_encode('anthropic/claude-3-haiku'),
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'ai_settings.updated',
            'actor_id' => $admin->id,
        ]);
    }

    public function test_admin_can_manage_ai_knowledge_base_lifecycle(): void
    {
        $admin = $this->createAdminUser(['ai.knowledge.manage']);

        // 1. Create document
        $createRes = $this->actingAs($admin, 'api')
            ->postJson('/api/v1/admin/ai/knowledge', [
                'title' => 'Return and Exchange Policy Guide',
                'category' => 'policy',
                'content' => 'Customers may return unworn items within 14 calendar days of delivery for a full refund.',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Return and Exchange Policy Guide')
            ->assertJsonPath('data.status', 'draft')
            ->assertJsonPath('data.version', 1);

        $docId = $createRes->json('data.id');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'ai_knowledge.created',
            'subject_type' => KnowledgeDocument::class,
            'subject_id' => $docId,
        ]);

        // 2. List documents
        $this->actingAs($admin, 'api')
            ->getJson('/api/v1/admin/ai/knowledge?category=policy')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.items.0.id', $docId);

        // 3. Update document (increments version)
        $this->actingAs($admin, 'api')
            ->patchJson("/api/v1/admin/ai/knowledge/{$docId}", [
                'content' => 'Updated: Customers may return unworn items within 30 calendar days of delivery.',
            ])
            ->assertOk()
            ->assertJsonPath('data.version', 2)
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'ai_knowledge.updated',
            'subject_type' => KnowledgeDocument::class,
            'subject_id' => $docId,
        ]);

        // 4. Index document
        $this->actingAs($admin, 'api')
            ->postJson("/api/v1/admin/ai/knowledge/{$docId}/index")
            ->assertOk()
            ->assertJsonPath('data.status', 'indexed');

        $this->assertDatabaseHas('audit_events', [
            'action' => 'ai_knowledge.indexed',
            'subject_type' => KnowledgeDocument::class,
            'subject_id' => $docId,
        ]);

        // 5. Delete document
        $this->actingAs($admin, 'api')
            ->deleteJson("/api/v1/admin/ai/knowledge/{$docId}")
            ->assertOk();

        $this->assertDatabaseMissing('ai_knowledge_documents', [
            'id' => $docId,
        ]);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'ai_knowledge.deleted',
            'subject_type' => KnowledgeDocument::class,
            'subject_id' => $docId,
        ]);
    }

    public function test_viewing_conversation_by_staff_records_audit_event(): void
    {
        $admin = $this->createAdminUser(['ai.read']);
        $customer = User::factory()->create(['role' => 'user']);
        $conversation = Conversation::create([
            'user_id' => $customer->id,
            'platform' => 'web',
            'status' => 'open',
        ]);

        $this->actingAs($admin, 'api')
            ->getJson("/api/v1/admin/ai/conversations/{$conversation->id}")
            ->assertOk();

        $this->assertDatabaseHas('audit_events', [
            'action' => 'ai_conversation.viewed',
            'subject_type' => Conversation::class,
            'subject_id' => $conversation->id,
            'actor_id' => $admin->id,
        ]);
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
