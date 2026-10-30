<?php

namespace Tests\Feature\AI;

use App\AI\AgentService;
use App\AI\Contracts\AgentDriver;
use App\AI\Data\AgentResponse;
use App\Http\Middleware\TrackAnalytics;
use App\Models\AI\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WebAIConversationEnhancementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_customer_can_send_images_with_message(): void
    {
        Storage::fake('public');

        $driver = new class implements AgentDriver
        {
            public function complete(
                array $messages,
                array $toolDefinitions,
                string $systemContext = '',
                bool $highReasoning = false,
            ): AgentResponse {
                return new AgentResponse('I inspected your uploaded photo and see the damaged packaging.', [], 'end_turn');
            }
        };

        $this->app->instance(AgentService::class, new AgentService($driver, []));

        $customer = User::factory()->create([
            'email' => 'vision@example.test',
            'role' => 'user',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'user_id' => $customer->id,
            'platform' => 'web',
            'status' => 'open',
        ]);

        $image = UploadedFile::fake()->image('defect.png', 400, 400);

        $response = $this->actingAs($customer, 'api')->postJson("/api/v1/ai/conversations/{$conversation->id}/messages", [
            'text' => 'The product arrived broken, please see attached photo.',
            'client_message_id' => 'browser-vision-001',
            'images' => [$image],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.role', 'assistant')
            ->assertJsonPath('data.content', 'I inspected your uploaded photo and see the damaged packaging.');

        $userMessage = $conversation->messages()->where('role', 'user')->first();
        $this->assertNotNull($userMessage);
        $this->assertNotEmpty($userMessage->metadata['images']);
        Storage::disk('public')->assertExists(
            str_replace('/storage/', '', $userMessage->metadata['images'][0])
        );
    }

    public function test_customer_can_hand_off_conversation_to_human_support(): void
    {
        $customer = User::factory()->create([
            'username' => 'janesupport',
            'email' => 'jane@example.test',
            'role' => 'user',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'user_id' => $customer->id,
            'platform' => 'web',
            'status' => 'open',
            'context' => ['source' => 'web_checkout'],
        ]);

        $response = $this->actingAs($customer, 'api')
            ->postJson("/api/v1/ai/conversations/{$conversation->id}/handoff", [
                'reason' => 'I need human escalation for a damaged order refund.',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.conversation.status', 'handed_off')
            ->assertJsonStructure([
                'data' => [
                    'conversation' => ['id', 'status'],
                    'ticket' => ['id', 'number', 'subject'],
                ],
            ]);

        $this->assertDatabaseHas('support_tickets', [
            'user_id' => $customer->id,
            'contact_email' => 'jane@example.test',
        ]);

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
        ]);
    }

    public function test_cannot_hand_off_closed_conversation(): void
    {
        $customer = User::factory()->create([
            'email' => 'closed-handoff@example.test',
            'role' => 'user',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'user_id' => $customer->id,
            'platform' => 'web',
            'status' => 'closed',
            'closed_at' => now(),
        ]);

        $this->actingAs($customer, 'api')
            ->postJson("/api/v1/ai/conversations/{$conversation->id}/handoff", [
                'reason' => 'Escalate this please.',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('conversation');
    }

    public function test_other_customer_cannot_hand_off_unowned_conversation(): void
    {
        $owner = User::factory()->create(['role' => 'user', 'status' => 'active']);
        $stranger = User::factory()->create(['role' => 'user', 'status' => 'active']);

        $conversation = Conversation::create([
            'user_id' => $owner->id,
            'platform' => 'web',
            'status' => 'open',
        ]);

        $this->actingAs($stranger, 'api')
            ->postJson("/api/v1/ai/conversations/{$conversation->id}/handoff")
            ->assertForbidden();
    }
}
