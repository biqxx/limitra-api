<?php

namespace App\AI\Drivers;

use App\AI\Contracts\AgentDriver;
use App\AI\Data\AgentResponse;
use Illuminate\Support\Facades\Http;

class GeminiDriver implements AgentDriver
{
    public function complete(array $messages, array $toolDefinitions, string $systemContext = ''): AgentResponse
    {
        $contents = array_map(fn (array $m) => [
            'role' => $m['role'] === 'assistant' ? 'model' : $m['role'],
            'parts' => [['text' => is_array($m['content']) ? json_encode($m['content']) : $m['content']]],
        ], $messages);

        $payload = [
            'system_instruction' => ['parts' => [['text' => config('ai.system_prompt').$systemContext]]],
            'contents' => $contents,
        ];

        if (! empty($toolDefinitions)) {
            $payload['tools'] = [['function_declarations' => $toolDefinitions]];
        }

        $model = config('ai.gemini.model');
        $base = config('ai.gemini.base_url');
        $apiKey = config('ai.gemini.api_key');

        $response = Http::timeout(30)
            ->post("{$base}/models/{$model}:generateContent?key={$apiKey}", $payload)
            ->throw()
            ->json();

        $toolCalls = [];
        $content = null;
        $finishReason = 'stop';

        $candidate = $response['candidates'][0] ?? [];
        $finishReason = strtolower($candidate['finishReason'] ?? 'stop');

        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (isset($part['text'])) {
                $content = $part['text'];
            } elseif (isset($part['functionCall'])) {
                $toolCalls[] = [
                    'id' => uniqid('gemini_tool_', true),
                    'name' => $part['functionCall']['name'],
                    'arguments' => $part['functionCall']['args'] ?? [],
                ];
            }
        }

        return new AgentResponse(
            content: $content,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
        );
    }
}
