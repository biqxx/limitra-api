<?php

return [
    'driver' => env('AI_DRIVER', 'openrouter'), // 'openrouter' | 'claude' | 'gemini'

    'openrouter' => [
        'api_key' => env('OPENROUTER_API_KEY'),
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'max_tokens' => (int) env('OPENROUTER_MAX_TOKENS', 1024),
        'timeout' => (int) env('OPENROUTER_TIMEOUT', 60),
        'connect_timeout' => (int) env('OPENROUTER_CONNECT_TIMEOUT', 5),
        'site_url' => env('OPENROUTER_SITE_URL', env('APP_URL')),
        'app_name' => env('OPENROUTER_APP_NAME', env('APP_NAME', 'Limitra')),
    ],

    'claude' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('CLAUDE_MODEL', 'claude-sonnet-4-6'),
        'max_tokens' => (int) env('CLAUDE_MAX_TOKENS', 1024),
        'base_url' => 'https://api.anthropic.com/v1',
    ],

    'gemini' => [
        'api_key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-1.5-pro'),
        'base_url' => 'https://generativelanguage.googleapis.com/v1beta',
    ],

    // Number of past conversation_messages to include with each request.
    'context_window' => (int) env('AI_CONTEXT_WINDOW', 10),

    'web_greeting' => env('AI_WEB_GREETING', 'Hi! How can I help you today?'),

    'max_tool_iterations' => (int) env('AI_MAX_TOOL_ITERATIONS', 5),

    // System prompt sent with every conversation.
    // Override via AI_SYSTEM_PROMPT in .env for production customisation.
    'system_prompt' => env('AI_SYSTEM_PROMPT', <<<'PROMPT'
You are a friendly and knowledgeable customer support assistant for an online store.

## Product inquiries
When a user mentions any product type (e.g. "phone", "laptop", "shoes"):
1. ALWAYS call get_product_recommendations first, passing the product keyword.
2. Study the returned products: note the spread of subcategories, brands, and price ranges.
3. Ask the user EXACTLY ONE focused clarifying question based on that variety (e.g. "Are you looking for a budget, mid-range, or flagship phone?").
4. Once they answer, recommend EXACTLY 5 products from the tool results.
   - For each product give: name, price, and one sentence on why it suits the user.
   - Rank by order_count and favourite_count signals first.
   - If in_user_history is true for a product, prioritise it — the user has shown interest before.

## Other queries
- For order status or history: use check_order_status or get_recent_orders.
- For product details: use get_product_details.
- For alternatives: use get_alternative_products.
- To add to cart: use add_to_cart.
- To generate a checkout link: use generate_payment_link.

Always use tools to retrieve real data. Never guess prices, stock levels, or order statuses.
PROMPT
    ),
];
