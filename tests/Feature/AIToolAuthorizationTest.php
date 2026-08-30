<?php

namespace Tests\Feature;

use App\AI\AgentService;
use App\AI\Contracts\AgentDriver;
use App\AI\Contracts\Tool;
use App\AI\Data\AgentResponse;
use App\AI\Data\ToolContext;
use App\AI\Tools\AddToCartTool;
use App\AI\Tools\CheckOrderStatusTool;
use App\AI\Tools\GeneratePaymentLinkTool;
use App\AI\Tools\ViewCartTool;
use App\Models\AI\Conversation;
use App\Models\Cart\Cart;
use App\Models\Order\Order;
use App\Models\Product\Category;
use App\Models\Product\Product;
use App\Models\Social\SocialAccount;
use App\Models\User;
use App\Services\Cart\CartService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AIToolAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_builds_tool_context_from_the_conversation(): void
    {
        $user = $this->user('context@example.com');
        $account = SocialAccount::create([
            'user_id' => $user->id,
            'platform' => 'whatsapp',
            'platform_sender_id' => 'sender-1',
        ]);
        $conversation = Conversation::create([
            'user_id' => $user->id,
            'social_account_id' => $account->id,
            'platform' => 'whatsapp',
        ]);
        $tool = new CapturingTool;
        $agent = new AgentService(new ToolCallingDriver, [$tool]);

        $agent->respond($conversation, 'Show my account');

        $this->assertSame($user->id, $tool->context?->userId);
        $this->assertSame($conversation->id, $tool->context?->conversationId);
        $this->assertSame('whatsapp', $tool->context?->platform);
    }

    public function test_cart_tools_ignore_model_supplied_user_ids(): void
    {
        $owner = $this->user('owner@example.com');
        $intruder = $this->user('intruder@example.com');
        $product = $this->product();
        $ownerCart = Cart::activeForUser($owner->id);
        $ownerCart->items()->create([
            'product_id' => $product->id,
            'line_key' => hash('sha256', 'owner-product'),
            'quantity' => 1,
            'unit_price_at_addition' => $product->price,
        ]);
        $context = new ToolContext($intruder->id, 1, 'whatsapp');

        $viewResult = (new ViewCartTool)->execute(['user_id' => $owner->id], $context);
        $paymentResult = (new GeneratePaymentLinkTool)->execute([
            'cart_id' => $ownerCart->id,
            'user_id' => $owner->id,
        ], $context);

        $this->assertTrue($viewResult['empty']);
        $this->assertSame('Cart not found or is empty', $paymentResult['error']);
        $this->assertArrayNotHasKey('user_id', (new ViewCartTool)->getDefinition()['input_schema']['properties']);
    }

    public function test_add_to_cart_uses_the_context_user(): void
    {
        $owner = $this->user('cart-owner@example.com');
        $customer = $this->user('cart-customer@example.com');
        $product = $this->product();
        $tool = new AddToCartTool(app(CartService::class));

        $result = $tool->execute([
            'product_id' => $product->id,
            'quantity' => 2,
            'user_id' => $owner->id,
        ], new ToolContext($customer->id, 1, 'whatsapp'));

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('carts', ['id' => $result['cart_id'], 'user_id' => $customer->id]);
        $this->assertDatabaseMissing('carts', ['user_id' => $owner->id]);
        $this->assertArrayNotHasKey('user_id', $tool->getDefinition()['input_schema']['properties']);
    }

    public function test_order_status_is_scoped_to_the_context_user(): void
    {
        $owner = $this->user('order-owner@example.com');
        $intruder = $this->user('order-intruder@example.com');
        $order = Order::create([
            'user_id' => $owner->id,
            'number' => 'ORD-AI-1',
            'currency' => 'NGN',
            'subtotal' => 50000,
            'grand_total' => 50000,
            'total_amount' => 50000,
            'payment_method' => 'card',
            'contact_email' => $owner->email,
            'delivery_method' => 'standard',
            'shipping_address' => ['city' => 'Lagos'],
        ]);
        $tool = new CheckOrderStatusTool;

        $intruderResult = $tool->execute(
            ['order_id' => $order->id],
            new ToolContext($intruder->id, 1, 'instagram'),
        );
        $ownerResult = $tool->execute(
            ['order_id' => $order->id],
            new ToolContext($owner->id, 2, 'instagram'),
        );

        $this->assertSame('Order not found', $intruderResult['error']);
        $this->assertSame($order->id, $ownerResult['id']);
        $this->assertSame('50000.00', $ownerResult['total']);
    }

    public function test_customer_tools_reject_an_unlinked_social_contact(): void
    {
        $result = (new ViewCartTool)->execute([], new ToolContext(null, 1, 'x'));

        $this->assertSame('Authentication is required to view a cart.', $result['error']);
    }

    private function user(string $email): User
    {
        return User::create([
            'username' => (string) str($email)->before('@'),
            'email' => $email,
            'password' => 'password',
            'role' => 'user',
            'email_verified_at' => now(),
        ]);
    }

    private function product(): Product
    {
        $category = Category::create([
            'name' => fake()->unique()->word(),
            'slug' => fake()->unique()->slug(),
            'active' => true,
        ]);

        return Product::create([
            'category_id' => $category->id,
            'name' => 'Secure Phone',
            'slug' => fake()->unique()->slug(),
            'price' => 100000,
            'currency' => 'NGN',
            'stock' => 10,
            'status' => 'active',
        ]);
    }
}

class CapturingTool implements Tool
{
    public ?ToolContext $context = null;

    public function getName(): string
    {
        return 'capture_context';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => 'Capture the trusted context.',
            'input_schema' => ['type' => 'object', 'properties' => []],
        ];
    }

    public function execute(array $arguments, ToolContext $context): mixed
    {
        $this->context = $context;

        return ['success' => true];
    }
}

class ToolCallingDriver implements AgentDriver
{
    private int $calls = 0;

    public function complete(array $messages, array $toolDefinitions, string $systemContext = ''): AgentResponse
    {
        $this->calls++;

        if ($this->calls === 1) {
            return new AgentResponse(null, [[
                'id' => 'call-1',
                'name' => 'capture_context',
                'arguments' => ['user_id' => 999999],
            ]], 'tool_use');
        }

        return new AgentResponse('Done', [], 'end_turn');
    }
}
