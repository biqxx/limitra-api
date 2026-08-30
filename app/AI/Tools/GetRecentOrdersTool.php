<?php

namespace App\AI\Tools;

use App\AI\Contracts\Tool;
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
                    'user_id' => ['type' => 'integer', 'description' => 'The user ID'],
                    'limit' => ['type' => 'integer', 'description' => 'Number of orders to return (default 5, max 10)'],
                ],
                'required' => ['user_id'],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $limit = min((int) ($arguments['limit'] ?? 5), 10);

        $orders = Order::where('user_id', (int) $arguments['user_id'])
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'status', 'total', 'created_at']);

        return $orders->map(fn ($o) => [
            'id' => $o->id,
            'status' => $o->status,
            'total' => $o->total,
            'created_at' => $o->created_at->toDateTimeString(),
        ])->toArray();
    }
}
