<?php

namespace App\AI\Data;

readonly class AgentResponse
{
    public function __construct(
        public ?string $content,
        public array $toolCalls,    // [{id, name, arguments}]
        public string $finishReason, // 'stop' | 'tool_use' | 'end_turn'
    ) {}
}
