<?php

namespace Tests\Feature;

use App\AI\AgentService;
use App\AI\Contracts\AgentDriver;
use App\AI\Contracts\Tool;
use App\AI\Data\AgentResponse;
use App\AI\Data\ToolContext;
use App\Http\Middleware\TrackAnalytics;
use App\Models\AI\Conversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebAIConversationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['jwt.secret' => 'test-secret-with-at-least-thirty-two-characters']);
        $this->withoutMiddleware(TrackAnalytics::class);
    }

    public function test_ai_service_provider_is_registered(): void
    {
        $this->assertInstanceOf(AgentService::class, $this->app->make(AgentService::class));
    }

    public function test_customer_can_create_message_browse_and_close_a_web_conversation(): void
    {
        $driver = new WebTestDriver;
        $this->app->instance(AgentService::class, new AgentService($driver, []));
        $customer = $this->user('customer@example.test');

        $created = $this->actingAs($customer, 'api')->postJson('/api/v1/ai/conversations', [
            'channel' => 'web',
            'context' => ['page' => '/products', 'product_id' => 12],
        ])->assertCreated()
            ->assertJsonPath('data.conversation.channel', 'web')
            ->assertJsonPath('data.conversation.status', 'open')
            ->assertJsonPath('data.greeting.role', 'assistant');

        $conversationId = $created->json('data.conversation.id');
        $this->actingAs($customer, 'api')->postJson("/api/v1/ai/conversations/{$conversationId}/messages", [
            'text' => 'Help me choose a phone.',
            'client_message_id' => 'browser-message-0001',
        ])->assertCreated()
            ->assertJsonPath('data.role', 'assistant')
            ->assertJsonPath('data.content', 'Here is a helpful answer.');

        $this->actingAs($customer, 'api')->getJson("/api/v1/ai/conversations/{$conversationId}/messages?per_page=10")
            ->assertOk()
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.1.content', 'Help me choose a phone.')
            ->assertJsonPath('data.items.2.content', 'Here is a helpful answer.');

        $this->actingAs($customer, 'api')->deleteJson("/api/v1/ai/conversations/{$conversationId}")
            ->assertOk()->assertJsonPath('data.status', 'closed');
        $this->actingAs($customer, 'api')->postJson("/api/v1/ai/conversations/{$conversationId}/messages", [
            'text' => 'Are you still there?',
            'client_message_id' => 'browser-message-0002',
        ])->assertUnprocessable()->assertJsonValidationErrors('conversation');

        $this->assertSame(1, $driver->calls);
    }

    public function test_message_replay_is_idempotent_and_rejects_changed_content(): void
    {
        $driver = new WebTestDriver;
        $this->app->instance(AgentService::class, new AgentService($driver, []));
        $customer = $this->user('idempotent@example.test');
        $conversationId = $this->conversation($customer)->id;
        $payload = ['text' => 'Where is my order?', 'client_message_id' => 'browser-message-replay'];

        $firstId = $this->actingAs($customer, 'api')
            ->postJson("/api/v1/ai/conversations/{$conversationId}/messages", $payload)
            ->assertCreated()->json('data.id');
        $this->actingAs($customer, 'api')
            ->postJson("/api/v1/ai/conversations/{$conversationId}/messages", $payload)
            ->assertOk()->assertJsonPath('data.id', $firstId);
        $this->actingAs($customer, 'api')
            ->postJson("/api/v1/ai/conversations/{$conversationId}/messages", [
                'text' => 'Different content',
                'client_message_id' => 'browser-message-replay',
            ])->assertUnprocessable()->assertJsonValidationErrors('client_message_id');

        $this->assertSame(1, $driver->calls);
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    public function test_tool_results_return_product_cards_and_safe_actions_without_exposing_tool_messages(): void
    {
        $driver = new WebToolCallingDriver;
        $tools = [
            new WebResultTool('get_product_recommendations', [
                'products' => [['id' => 7, 'name' => 'Limitra Phone', 'price' => '150000.00']],
            ]),
            new WebResultTool('generate_payment_link', [
                'checkout_url' => 'https://shop.example.test/checkout/safe-token',
            ]),
        ];
        $this->app->instance(AgentService::class, new AgentService($driver, $tools));
        $customer = $this->user('tools@example.test');
        $conversationId = $this->conversation($customer)->id;

        $this->actingAs($customer, 'api')->postJson("/api/v1/ai/conversations/{$conversationId}/messages", [
            'text' => 'Find a phone and help me check out.',
            'client_message_id' => 'browser-message-tools',
        ])->assertCreated()
            ->assertJsonPath('data.products.0.id', 7)
            ->assertJsonPath('data.actions.0.type', 'open_checkout')
            ->assertJsonPath('data.actions.0.url', 'https://shop.example.test/checkout/safe-token');

        $this->actingAs($customer, 'api')->getJson("/api/v1/ai/conversations/{$conversationId}/messages")
            ->assertOk()->assertJsonCount(2, 'data.items');
        $this->assertDatabaseCount('conversation_messages', 4);
    }

    public function test_web_conversations_are_owner_scoped_and_require_authentication(): void
    {
        $owner = $this->user('owner@example.test');
        $other = $this->user('other@example.test');
        $conversation = $this->conversation($owner);

        $this->postJson('/api/v1/ai/conversations')->assertUnauthorized();
        $this->actingAs($other, 'api')->getJson("/api/v1/ai/conversations/{$conversation->id}/messages")
            ->assertForbidden();
        $this->actingAs($other, 'api')->deleteJson("/api/v1/ai/conversations/{$conversation->id}")
            ->assertForbidden();
    }

    public function test_high_reasoning_conversation_context_is_forwarded_to_the_driver(): void
    {
        $driver = new WebTestDriver;
        $this->app->instance(AgentService::class, new AgentService($driver, []));
        $customer = $this->user('reasoning@example.test');

        $conversationId = $this->actingAs($customer, 'api')->postJson('/api/v1/ai/conversations', [
            'context' => ['reasoning' => 'high'],
        ])->assertCreated()->json('data.conversation.id');

        $this->actingAs($customer, 'api')->postJson("/api/v1/ai/conversations/{$conversationId}/messages", [
            'text' => 'Compare these options carefully.',
            'client_message_id' => 'browser-high-reasoning',
        ])->assertCreated();

        $this->assertSame([true], $driver->highReasoningModes);
    }

    private function conversation(User $user): Conversation
    {
        return Conversation::query()->create([
            'user_id' => $user->id,
            'platform' => 'web',
            'status' => 'open',
            'last_message_at' => now(),
        ]);
    }

    private function user(string $email): User
    {
        return User::query()->create([
            'username' => (string) Str::of($email)->before('@'),
            'email' => $email,
            'password' => 'password',
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
    }
}

class WebTestDriver implements AgentDriver
{
    public int $calls = 0;

    public array $highReasoningModes = [];

    public function complete(
        array $messages,
        array $toolDefinitions,
        string $systemContext = '',
        bool $highReasoning = false,
    ): AgentResponse {
        $this->calls++;
        $this->highReasoningModes[] = $highReasoning;

        return new AgentResponse('Here is a helpful answer.', [], 'end_turn');
    }
}

class WebToolCallingDriver implements AgentDriver
{
    private int $calls = 0;

    public function complete(
        array $messages,
        array $toolDefinitions,
        string $systemContext = '',
        bool $highReasoning = false,
    ): AgentResponse {
        $this->calls++;

        if ($this->calls === 1) {
            return new AgentResponse(null, [
                ['id' => 'product-call', 'name' => 'get_product_recommendations', 'arguments' => ['query' => 'phone']],
                ['id' => 'checkout-call', 'name' => 'generate_payment_link', 'arguments' => ['cart_id' => 1]],
            ], 'tool_use');
        }

        return new AgentResponse('I found a phone for you.', [], 'end_turn');
    }
}

class WebResultTool implements Tool
{
    public function __construct(private readonly string $name, private readonly array $result) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->name,
            'description' => 'Return a deterministic web conversation test result.',
            'input_schema' => ['type' => 'object', 'properties' => []],
        ];
    }

    public function execute(array $arguments, ToolContext $context): mixed
    {
        return $this->result;
    }
}
