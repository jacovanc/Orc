<?php

return [

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

    'amp' => [
        'enabled' => env('AMP_INTEGRATION_ENABLED', false),
        'launch_webhook_url' => env('AMP_LAUNCH_WEBHOOK_URL'),
        'launch_signing_secret' => env('AMP_LAUNCH_SIGNING_SECRET'),
        'callback_signing_secret' => env('AMP_CALLBACK_SIGNING_SECRET'),
        'signature_tolerance_seconds' => (int) env('AMP_SIGNATURE_TOLERANCE_SECONDS', 300),
        'allowed_repositories' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AMP_ALLOWED_REPOSITORIES', '')),
        ))),
        'allowed_user_emails' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('AMP_ALLOWED_USER_EMAILS', '')),
        ))),
    ],

];
