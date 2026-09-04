<?php

namespace App\AI\Contracts;

use App\AI\Data\AgentResponse;

interface AgentDriver
{
    /**
     * @param  array<int, array{role: string, content: string|array<mixed>}>  $messages
     * @param  array<int, array<string, mixed>>  $toolDefinitions
     * @param  string  $systemContext  Per-conversation context appended to the base system prompt (user_id, platform).
     */
    public function complete(
        array $messages,
        array $toolDefinitions,
        string $systemContext = '',
        bool $highReasoning = false,
    ): AgentResponse;
}
