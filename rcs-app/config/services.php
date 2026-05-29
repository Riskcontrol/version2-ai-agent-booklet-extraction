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

    'partner' => [
        'base_url' => env('PARTNER_BILLING_BASE_URL', 'https://extract.peldargconsulting.com'),
        'token' => env('PARTNER_SHARED_TOKEN'),
        'partner_name' => env('PARTNER_NAME', 'riskcontrol'),
        'signature_secret' => env('PARTNER_SIGNATURE_SECRET', ''),
        'timeout' => (int) env('PARTNER_TIMEOUT_SECONDS', 15),
        'audit_admin_emails' => env('PARTNER_AUDIT_ADMIN_EMAILS', 'admin@rcsn.com'),

        // Phase 7 migration strategy controls.
        // shadow: continue extraction even when authorization fails, while logging all decisions.
        // hard_block: enforce authorization failures immediately.
        'integration_mode' => env('PARTNER_INTEGRATION_MODE', 'shadow'),
        'shadow_started_at' => env('PARTNER_SHADOW_STARTED_AT'),
        'hard_block_after_days' => (int) env('PARTNER_HARD_BLOCK_AFTER_DAYS', 7),

        // Disable direct RiskControl top-up paths after cutover.
        'freeze_direct_topup' => (bool) env('PARTNER_FREEZE_DIRECT_TOPUP', true),

        // User migration endpoint in Peldarg.
        'migration_path' => env('PARTNER_USER_MIGRATION_PATH', '/api/partner/migrate-user'),
        'default_opening_balance' => (int) env('PARTNER_DEFAULT_OPENING_BALANCE', 0),
        'default_opening_cap' => (int) env('PARTNER_DEFAULT_OPENING_CAP', 0),

        // Tracking UI and read-side observability controls.
        'tracking_ui_enabled' => (bool) env('PARTNER_TRACKING_UI_ENABLED', true),
        'tracking_observability_enabled' => (bool) env('PARTNER_TRACKING_OBSERVABILITY_ENABLED', true),
    ],

];
