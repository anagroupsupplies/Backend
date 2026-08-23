<?php

return [

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID', 'anagroupsupplies'),
        'admin_emails' => array_filter(array_map('trim', explode(',', env('ADMIN_EMAILS', '')))),
        'master_admin_emails' => array_filter(array_map('trim', explode(',', env('MASTER_ADMIN_EMAILS', '')))),
    ],

    'malipopay' => [
        'base_url' => env('MALIPOPAY_BASE_URL', 'https://core-prod.malipopay.co.tz'),
        'api_token' => env('MALIPOPAY_API_TOKEN'),
        'webhook_secret' => env('MALIPOPAY_WEBHOOK_SECRET'),
        'timeout' => (int) env('MALIPOPAY_TIMEOUT', 30),
    ],

    'deepseek' => [
        'api_key' => env('DEEPSEEK_API_KEY'),
        'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com'),
        'model' => env('DEEPSEEK_MODEL', 'deepseek-v4-flash'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
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

];
