<?php

namespace App\AI\Contracts;

use App\AI\Data\ToolContext;

interface Tool
{
    public function getName(): string;

    /** Returns the JSON schema the AI uses to call this tool. */
    public function getDefinition(): array;

    /** Executes the tool and returns a result the AI can read. */
    public function execute(array $arguments, ToolContext $context): mixed;
}
