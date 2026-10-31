<?php

namespace App\AI\Data;

readonly class AgentResponse
{
    public function __construct(
        public ?string $content = null,
        public array $toolCalls = [],    // [{id, name, arguments}]
        public string $finishReason = 'end_turn', // 'stop' | 'tool_use' | 'end_turn'
    ) {}
}
