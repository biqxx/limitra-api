<?php

namespace App\AI\Drivers;

use App\AI\Contracts\AgentDriver;
use App\AI\Data\AgentResponse;
use App\Services\Settings\BusinessSettingsService;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use JsonException;

class OpenRouterDriver implements AgentDriver
{
    public function __construct(
        private readonly BusinessSettingsService $settings,
    ) {}

    public function complete(
        array $messages,
        array $toolDefinitions,
        string $systemContext = '',
        bool $highReasoning = false,
    ): AgentResponse {
        $payload = [
            'models' => $this->models($highReasoning),
            'max_tokens' => config('ai.openrouter.max_tokens'),
            'messages' => [
                [
                    'role' => 'system',
                    'content' => config('ai.system_prompt').$systemContext,
                ],
                ...$this->normalizeMessages($messages),
            ],
        ];

        if (! empty($toolDefinitions)) {
            $payload['tools'] = array_map(
                fn (array $definition): array => [
                    'type' => 'function',
                    'function' => [
                        'name' => $definition['name'],
                        'description' => $definition['description'] ?? '',
                        'parameters' => $definition['input_schema'] ?? [
                            'type' => 'object',
                            'properties' => [],
                        ],
                    ],
                ],
                $toolDefinitions,
            );
        }

        if ($highReasoning) {
            $payload['reasoning'] = [
                'effort' => 'high',
                'exclude' => true,
            ];
        }

        $response = Http::baseUrl(rtrim((string) config('ai.openrouter.base_url'), '/'))
            ->withToken((string) config('ai.openrouter.api_key'))
            ->acceptJson()
            ->withHeaders(array_filter([
                'HTTP-Referer' => config('ai.openrouter.site_url'),
                'X-Title' => config('ai.openrouter.app_name'),
            ]))
            ->connectTimeout((int) config('ai.openrouter.connect_timeout'))
            ->timeout((int) config('ai.openrouter.timeout'))
            ->retry([200, 500, 1000], function (Exception $exception, PendingRequest $request): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError());
            })
            ->post('chat/completions', $payload)
            ->throw()
            ->json();

        $message = data_get($response, 'choices.0.message', []);
        $toolCalls = array_map(
            fn (array $toolCall): array => [
                'id' => $toolCall['id'],
                'name' => data_get($toolCall, 'function.name'),
                'arguments' => $this->decodeArguments(data_get($toolCall, 'function.arguments')),
            ],
            $message['tool_calls'] ?? [],
        );

        return new AgentResponse(
            content: $message['content'] ?? null,
            toolCalls: $toolCalls,
            finishReason: data_get($response, 'choices.0.finish_reason', 'stop'),
        );
    }

    /** @return array<int, string> */
    private function models(bool $highReasoning): array
    {
        $normalModels = [
            (string) $this->settings->value('ai.primary_model'),
            (string) $this->settings->value('ai.fallback_model'),
        ];

        if ($highReasoning) {
            array_unshift(
                $normalModels,
                (string) $this->settings->value('ai.high_reasoning_model'),
            );
        }

        return array_values(array_unique($normalModels));
    }

    /**
     * @param  array<int, array{role: string, content: string|array<mixed>}>  $messages
     * @return array<int, array<string, mixed>>
     */
    private function normalizeMessages(array $messages): array
    {
        $normalized = [];

        foreach ($messages as $message) {
            if ($message['role'] === 'assistant' && is_array($message['content'])) {
                $normalized[] = $this->normalizeAssistantMessage($message['content']);

                continue;
            }

            if ($message['role'] === 'user' && is_array($message['content'])) {
                foreach ($message['content'] as $block) {
                    if (($block['type'] ?? null) === 'tool_result') {
                        $normalized[] = [
                            'role' => 'tool',
                            'tool_call_id' => $block['tool_use_id'],
                            'content' => is_string($block['content'])
                                ? $block['content']
                                : json_encode($block['content'], JSON_THROW_ON_ERROR),
                        ];
                    }
                }

                continue;
            }

            $normalized[] = [
                'role' => $message['role'],
                'content' => $message['content'],
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function normalizeAssistantMessage(array $blocks): array
    {
        $text = [];
        $toolCalls = [];

        foreach ($blocks as $block) {
            if (($block['type'] ?? null) === 'text') {
                $text[] = $block['text'];
            }

            if (($block['type'] ?? null) === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'],
                    'type' => 'function',
                    'function' => [
                        'name' => $block['name'],
                        'arguments' => json_encode($block['input'] ?? [], JSON_THROW_ON_ERROR),
                    ],
                ];
            }
        }

        return array_filter([
            'role' => 'assistant',
            'content' => $text === [] ? null : implode("\n", $text),
            'tool_calls' => $toolCalls === [] ? null : $toolCalls,
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function decodeArguments(mixed $arguments): array
    {
        if (is_array($arguments)) {
            return $arguments;
        }

        if (! is_string($arguments) || $arguments === '') {
            return [];
        }

        try {
            $decoded = json_decode($arguments, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }
}
