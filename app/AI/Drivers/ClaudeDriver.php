<?php

namespace App\AI\Drivers;

use App\AI\Contracts\AgentDriver;
use App\AI\Data\AgentResponse;
use Illuminate\Support\Facades\Http;

class ClaudeDriver implements AgentDriver
{
    public function complete(array $messages, array $toolDefinitions, string $systemContext = ''): AgentResponse
    {
        $payload = [
            'model' => config('ai.claude.model'),
            'max_tokens' => config('ai.claude.max_tokens'),
            'system' => config('ai.system_prompt').$systemContext,
            'messages' => $messages,
        ];

        if (! empty($toolDefinitions)) {
            $payload['tools'] = $toolDefinitions;
        }

        $response = Http::withHeaders([
            'x-api-key' => config('ai.claude.api_key'),
            'anthropic-version' => '2023-06-01',
            'content-type' => 'application/json',
        ])
            ->timeout(30)
            ->post(config('ai.claude.base_url').'/messages', $payload)
            ->throw()
            ->json();

        $toolCalls = [];
        $content = null;
        $stopReason = $response['stop_reason'] ?? 'end_turn';

        foreach ($response['content'] ?? [] as $block) {
            if ($block['type'] === 'text') {
                $content = $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $toolCalls[] = [
                    'id' => $block['id'],
                    'name' => $block['name'],
                    'arguments' => $block['input'],
                ];
            }
        }

        return new AgentResponse(
            content: $content,
            toolCalls: $toolCalls,
            finishReason: $stopReason,
        );
    }
}
