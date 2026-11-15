<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'stripe' => [
        // Secret key (sk_test_... / sk_live_...). When empty, the app uses the
        // fake payment gateway, so local development works without Stripe.
        'secret' => env('STRIPE_SECRET'),
        // From the Stripe dashboard's webhook endpoint, or `stripe listen` locally.
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],

    // UPS Rating API. When client_id is empty, checkout uses the flat rate.
    'ups' => [
        'client_id' => env('UPS_CLIENT_ID'),
        'client_secret' => env('UPS_CLIENT_SECRET'),
        'account_number' => env('UPS_ACCOUNT_NUMBER'),
        // Sandbox by default; production is https://onlinetools.ups.com.
        'base_url' => env('UPS_BASE_URL', 'https://wwwcie.ups.com'),
        'ship_from' => [
            'name' => env('UPS_SHIP_FROM_NAME', env('APP_NAME')),
            'line1' => env('UPS_SHIP_FROM_LINE1'),
            'city' => env('UPS_SHIP_FROM_CITY'),
            'state' => env('UPS_SHIP_FROM_STATE'),
            'postal_code' => env('UPS_SHIP_FROM_POSTAL_CODE'),
            'country' => env('UPS_SHIP_FROM_COUNTRY', 'US'),
        ],
        // Pieces don't have their own size and weight yet, so every piece
        // ships in this default box (inches, pounds).
        'default_package' => [
            'length' => env('UPS_PACKAGE_LENGTH', 24),
            'width' => env('UPS_PACKAGE_WIDTH', 20),
            'height' => env('UPS_PACKAGE_HEIGHT', 4),
            'weight' => env('UPS_PACKAGE_WEIGHT', 8),
        ],
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
