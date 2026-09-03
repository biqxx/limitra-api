<?php

namespace App\AI;

use App\AI\Contracts\AgentDriver;
use App\AI\Contracts\Tool;
use App\AI\Data\AgentResponse;
use App\AI\Data\ToolContext;
use App\Models\AI\Conversation;
use App\Models\AI\ConversationMessage;

class AgentService
{
    /**
     * @param  Tool[]  $tools
     */
    public function __construct(
        private readonly AgentDriver $driver,
        private readonly array $tools,
    ) {}

    /**
     * Persist the user's message, run the tool-calling loop, and return the final assistant reply.
     */
    public function respond(Conversation $conversation, string $userMessage): string
    {
        $requestMessage = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => $userMessage,
        ]);

        return $this->respondToMessage($conversation, $requestMessage)->content
            ?? 'I\'m sorry, I could not generate a response.';
    }

    public function respondToMessage(
        Conversation $conversation,
        ConversationMessage $requestMessage,
    ): ConversationMessage {
        if ($requestMessage->conversation_id !== $conversation->id || $requestMessage->role !== 'user') {
            throw new \InvalidArgumentException('The AI request message does not belong to this conversation.');
        }

        $toolDefinitions = $this->resolveToolDefinitions();
        $messages = $this->buildMessages($conversation);
        $systemContext = $this->buildSystemContext($conversation);
        $toolContext = ToolContext::fromConversation($conversation);
        $toolIterations = 0;

        // Tool-calling loop: keep calling the driver until it stops requesting tools.
        do {
            $response = $this->driver->complete($messages, $toolDefinitions, $systemContext);
            $toolIterations++;

            if (! empty($response->toolCalls) && $toolIterations >= config('ai.max_tool_iterations', 5)) {
                $response = new AgentResponse(
                    'I could not complete that request safely. Please try a more specific question.',
                    [],
                    'tool_limit',
                );
            }

            if (! empty($response->toolCalls)) {
                // Persist the assistant's tool-call turn.
                ConversationMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'assistant',
                    'content' => $response->content,
                    'tool_calls' => $response->toolCalls,
                ]);

                $toolResults = [];

                foreach ($response->toolCalls as $call) {
                    $result = $this->executeTool($call['name'], $call['arguments'], $toolContext);

                    $toolResults[] = [
                        'tool_call_id' => $call['id'],
                        'name' => $call['name'],
                        'result' => $result,
                    ];
                }

                ConversationMessage::create([
                    'conversation_id' => $conversation->id,
                    'role' => 'tool',
                    'tool_results' => $toolResults,
                ]);

                // Rebuild messages including the new tool results for the next iteration.
                $messages = $this->buildMessages($conversation->fresh());
                $systemContext = $this->buildSystemContext($conversation);
            }
        } while (! empty($response->toolCalls));

        $finalContent = $response->content ?? 'I\'m sorry, I could not generate a response.';

        $assistantMessage = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $finalContent,
            'metadata' => ['finish_reason' => $response->finishReason],
            'request_message_id' => $requestMessage->id,
        ]);

        $conversation->forceFill(['last_message_at' => $assistantMessage->created_at])->save();

        return $assistantMessage;
    }

    private function buildSystemContext(Conversation $conversation): string
    {
        return sprintf(
            "\n\nSession context: user_id=%s, platform=%s.",
            $conversation->user_id ?? 'unknown',
            $conversation->platform,
        );
    }

    private function buildMessages(Conversation $conversation): array
    {
        $window = config('ai.context_window', 10);

        $dbMessages = $conversation->messages()
            ->orderByDesc('id')
            ->limit($window)
            ->get()
            ->reverse()
            ->values();

        $messages = [];

        foreach ($dbMessages as $msg) {
            if ($msg->role === 'tool') {
                // Tool results are appended to the preceding assistant message as a user turn.
                $messages[] = [
                    'role' => 'user',
                    'content' => array_map(fn (array $r) => [
                        'type' => 'tool_result',
                        'tool_use_id' => $r['tool_call_id'],
                        'content' => json_encode($r['result']),
                    ], $msg->tool_results ?? []),
                ];
            } elseif ($msg->role === 'assistant' && ! empty($msg->tool_calls)) {
                $content = [];
                if ($msg->content) {
                    $content[] = ['type' => 'text', 'text' => $msg->content];
                }
                foreach ($msg->tool_calls as $call) {
                    $content[] = [
                        'type' => 'tool_use',
                        'id' => $call['id'],
                        'name' => $call['name'],
                        'input' => $call['arguments'],
                    ];
                }
                $messages[] = ['role' => 'assistant', 'content' => $content];
            } else {
                $messages[] = [
                    'role' => $msg->role,
                    'content' => $msg->content ?? '',
                ];
            }
        }

        return $messages;
    }

    private function executeTool(string $name, array $arguments, ToolContext $context): mixed
    {
        foreach ($this->tools as $tool) {
            if ($tool->getName() === $name) {
                return $tool->execute($arguments, $context);
            }
        }

        return ['error' => "Tool '{$name}' not found"];
    }

    private function resolveToolDefinitions(): array
    {
        return array_map(fn (Tool $t) => $t->getDefinition(), $this->tools);
    }
}
