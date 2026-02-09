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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
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

    'github' => [
        'pat' => env('GITHUB_PAT'),
    ],

    'extractor' => [
        // Backward/forward compatible env var names.
        // GitHub Actions workflow uses CALLBACK_HMAC_SECRET + RESULT_UPLOAD_TOKEN.
        // Older deployments used EXTRACTOR_CALLBACK_SECRET + EXTRACTOR_BEARER_TOKEN.
        'secret' => env('CALLBACK_HMAC_SECRET', env('EXTRACTOR_CALLBACK_SECRET')),
        'token' => env('RESULT_UPLOAD_TOKEN', env('EXTRACTOR_BEARER_TOKEN')),
    ],

];
