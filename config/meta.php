<?php

return [
    'verify_token' => env('META_VERIFY_TOKEN'),
    'app_secret' => env('META_APP_SECRET'),

    'whatsapp' => [
        'token' => env('META_WHATSAPP_TOKEN'),
        'number_id' => env('META_WHATSAPP_NUMBER_ID'),
        'base_url' => 'https://graph.facebook.com/v19.0',
    ],

    'instagram' => [
        'token' => env('META_INSTAGRAM_TOKEN'),
        'page_id' => env('META_INSTAGRAM_PAGE_ID'),
        'base_url' => 'https://graph.facebook.com/v19.0',
    ],

    'facebook' => [
        'token' => env('META_FACEBOOK_TOKEN'),
        'page_id' => env('META_FACEBOOK_PAGE_ID'),
        'base_url' => 'https://graph.facebook.com/v19.0',
    ],
];
