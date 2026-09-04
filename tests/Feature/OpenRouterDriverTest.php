<?php

namespace Tests\Feature;

use App\AI\Contracts\AgentDriver;
use App\AI\Drivers\OpenRouterDriver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenRouterDriverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.openrouter.api_key' => 'test-openrouter-key',
            'ai.openrouter.base_url' => 'https://openrouter.test/api/v1',
            'ai.openrouter.site_url' => 'https://limitra.test',
            'ai.openrouter.app_name' => 'Limitra Test',
        ]);

        Http::preventStrayRequests();
    }

    public function test_standard_requests_use_ling_then_solar_and_translate_tools(): void
    {
        Http::fake([
            'https://openrouter.test/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'Here are the matching products.',
                    ],
                ]],
            ]),
        ]);

        $response = app(OpenRouterDriver::class)->complete(
            messages: [['role' => 'user', 'content' => 'Find me a phone.']],
            toolDefinitions: [[
                'name' => 'search_products',
                'description' => 'Search the product catalog.',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => ['query' => ['type' => 'string']],
                    'required' => ['query'],
                ],
            ]],
        );

        $this->assertSame('Here are the matching products.', $response->content);
        $this->assertSame([], $response->toolCalls);
        $this->assertSame('stop', $response->finishReason);

        Http::assertSent(function (Request $request): bool {
            return $request->url() === 'https://openrouter.test/api/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-openrouter-key')
                && $request->hasHeader('HTTP-Referer', 'https://limitra.test')
                && $request->hasHeader('X-Title', 'Limitra Test')
                && $request['models'] === [
                    'inclusionai/ling-3.0-flash',
                    'upstage/solar-pro4',
                ]
                && data_get($request->data(), 'tools.0.type') === 'function'
                && data_get($request->data(), 'tools.0.function.name') === 'search_products'
                && data_get($request->data(), 'tools.0.function.parameters.required') === ['query']
                && ! array_key_exists('reasoning', $request->data());
        });
    }

    public function test_openrouter_is_the_default_driver(): void
    {
        $this->assertInstanceOf(OpenRouterDriver::class, app(AgentDriver::class));
    }

    public function test_high_reasoning_requests_prioritize_deepseek_and_hide_reasoning(): void
    {
        Http::fake([
            'https://openrouter.test/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call-product-search',
                            'type' => 'function',
                            'function' => [
                                'name' => 'search_products',
                                'arguments' => '{"query":"laptop"}',
                            ],
                        ]],
                    ],
                ]],
            ]),
        ]);

        $response = app(OpenRouterDriver::class)->complete(
            messages: [['role' => 'user', 'content' => 'Compare the best laptop options.']],
            toolDefinitions: [],
            highReasoning: true,
        );

        $this->assertNull($response->content);
        $this->assertSame('tool_calls', $response->finishReason);
        $this->assertSame([
            [
                'id' => 'call-product-search',
                'name' => 'search_products',
                'arguments' => ['query' => 'laptop'],
            ],
        ], $response->toolCalls);

        Http::assertSent(fn (Request $request): bool => $request['models'] === [
            'deepseek/deepseek-v4-flash-0731',
            'inclusionai/ling-3.0-flash',
            'upstage/solar-pro4',
        ] && $request['reasoning'] === [
            'effort' => 'high',
            'exclude' => true,
        ]);
    }

    public function test_anthropic_style_tool_history_is_translated_to_openai_messages(): void
    {
        Http::fake([
            'https://openrouter.test/api/v1/chat/completions' => Http::response([
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'Done.'],
                ]],
            ]),
        ]);

        app(OpenRouterDriver::class)->complete(
            messages: [
                ['role' => 'user', 'content' => 'Show product 12.'],
                ['role' => 'assistant', 'content' => [[
                    'type' => 'tool_use',
                    'id' => 'call-product',
                    'name' => 'get_product_details',
                    'input' => ['product_id' => 12],
                ]]],
                ['role' => 'user', 'content' => [[
                    'type' => 'tool_result',
                    'tool_use_id' => 'call-product',
                    'content' => '{"id":12,"name":"Limitra Phone"}',
                ]]],
            ],
            toolDefinitions: [],
        );

        Http::assertSent(function (Request $request): bool {
            $messages = $request['messages'];

            return data_get($messages, '2.role') === 'assistant'
                && data_get($messages, '2.tool_calls.0.id') === 'call-product'
                && data_get($messages, '2.tool_calls.0.function.arguments') === '{"product_id":12}'
                && data_get($messages, '3.role') === 'tool'
                && data_get($messages, '3.tool_call_id') === 'call-product';
        });
    }
}
