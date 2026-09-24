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

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'africastalking' => [
        'username' => env('AFRICASTALKING_USERNAME', 'sandbox'),
        'api_key' => env('AFRICASTALKING_API_KEY'),
        // Optional alphanumeric sender ID or short code registered with AT.
        'sender_id' => env('AFRICASTALKING_SENDER_ID'),
    ],

    // External results dashboard that receives every result, incident and
    // presence check-in. Leave the URL empty to disable delivery.
    'dashboard' => [
        'url' => env('DASHBOARD_WEBHOOK_URL'),
        'token' => env('DASHBOARD_API_TOKEN'),
        'secret' => env('DASHBOARD_WEBHOOK_SECRET'),
        'timeout' => (int) env('DASHBOARD_TIMEOUT', 10),
    ],

];
