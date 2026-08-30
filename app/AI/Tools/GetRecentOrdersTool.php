<?php

namespace App\AI\Tools;

use App\AI\Contracts\Tool;
use App\AI\Data\ToolContext;
use App\Models\Order\Order;

class GetRecentOrdersTool implements Tool
{
    public function getName(): string
    {
        return 'get_recent_orders';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => "Get a customer's recent orders.",
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'limit' => ['type' => 'integer', 'description' => 'Number of orders to return (default 5, max 10)'],
                ],
                'required' => [],
            ],
        ];
    }

    public function execute(array $arguments, ToolContext $context): mixed
    {
        if ($context->userId === null) {
            return ['error' => 'Authentication is required to view orders.'];
        }

        $limit = max(1, min((int) ($arguments['limit'] ?? 5), 10));

        $orders = Order::where('user_id', $context->userId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'status', 'grand_total', 'created_at']);

        return $orders->map(fn ($o) => [
            'id' => $o->id,
            'status' => $o->status,
            'total' => $o->grand_total,
            'created_at' => $o->created_at->toDateTimeString(),
        ])->toArray();
    }
}
