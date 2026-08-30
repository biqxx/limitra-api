<?php

return [
    'consumer_key' => env('X_CONSUMER_KEY'),
    'consumer_secret' => env('X_CONSUMER_SECRET'),

    // Access credentials for the bot account (user-level OAuth 1.0a).
    // Required for sending DMs — bearer token alone is read-only.
    'access_token' => env('X_ACCESS_TOKEN'),
    'access_secret' => env('X_ACCESS_SECRET'),

    // The bot account's own user ID — used to filter out self-sent events.
    'bot_user_id' => env('X_BOT_USER_ID'),

    'base_url' => 'https://api.twitter.com',
];
