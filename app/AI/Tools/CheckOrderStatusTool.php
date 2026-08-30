<?php

namespace App\AI\Tools;

use App\AI\Contracts\Tool;
use App\Models\Order\Order;

class CheckOrderStatusTool implements Tool
{
    public function getName(): string
    {
        return 'check_order_status';
    }

    public function getDefinition(): array
    {
        return [
            'name' => $this->getName(),
            'description' => 'Check the current status of an order by its ID.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'order_id' => ['type' => 'integer', 'description' => 'The order ID'],
                ],
                'required' => ['order_id'],
            ],
        ];
    }

    public function execute(array $arguments): mixed
    {
        $order = Order::with('items')->find((int) $arguments['order_id']);

        if (! $order) {
            return ['error' => 'Order not found'];
        }

        return [
            'id' => $order->id,
            'status' => $order->status,
            'total' => $order->total,
            'item_count' => $order->items->count(),
            'created_at' => $order->created_at->toDateTimeString(),
        ];
    }
}
