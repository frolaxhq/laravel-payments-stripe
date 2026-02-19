<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Stripe API Configuration
    |--------------------------------------------------------------------------
    */

    'base_url' => env('STRIPE_BASE_URL', 'https://api.stripe.com'),

    'api_version' => env('STRIPE_API_VERSION', '2024-12-18.acacia'),

    /*
    |--------------------------------------------------------------------------
    | Credentials
    |--------------------------------------------------------------------------
    */

    'test' => [
        'secret_key' => env('STRIPE_TEST_SECRET_KEY'),
        'publishable_key' => env('STRIPE_TEST_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_TEST_WEBHOOK_SECRET'),
    ],

    'live' => [
        'secret_key' => env('STRIPE_LIVE_SECRET_KEY'),
        'publishable_key' => env('STRIPE_LIVE_PUBLISHABLE_KEY'),
        'webhook_secret' => env('STRIPE_LIVE_WEBHOOK_SECRET'),
    ],
];
