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

    'arbor' => [
        'base_url' => env('ARBOR_URL'),
        'username' => env('ARBOR_APP_USERNAME'),
        'password' => env('ARBOR_APP_PASSWORD'),
        // Unused legacy consent tool has a separate account; disabled unless explicitly configured.
        'legacy_consent' => [
            'url' => env('ARBOR_LEGACY_CONSENT_URL'),
            'username' => env('ARBOR_LEGACY_CONSENT_USERNAME'),
            'password' => env('ARBOR_LEGACY_CONSENT_PASSWORD'),
        ],
        'rate_limit' => [
            'redis_connection' => env('ARBOR_RATE_LIMIT_REDIS_CONNECTION', 'arbor_limiter'),
            'key' => env('ARBOR_RATE_LIMIT_KEY', 'arbor:writeback'),
            'requests' => (int) env('ARBOR_RATE_LIMIT_REQUESTS', 240),
            'window_ms' => (int) env('ARBOR_RATE_LIMIT_WINDOW_MS', 60000),
            'min_interval_ms' => (int) env('ARBOR_RATE_LIMIT_MIN_INTERVAL_MS', 250),
            'max_wait_ms' => (int) env('ARBOR_RATE_LIMIT_MAX_WAIT_MS', 1000),
            'cooldown_seconds' => (int) env('ARBOR_RATE_LIMIT_COOLDOWN_SECONDS', 60),
            'connect_timeout_seconds' => (int) env('ARBOR_CONNECT_TIMEOUT_SECONDS', 5),
            'request_timeout_seconds' => (int) env('ARBOR_REQUEST_TIMEOUT_SECONDS', 15),
            'job_budget_seconds' => (int) env('ARBOR_WRITEBACK_JOB_BUDGET_SECONDS', 45),
        ],
        'sync' => [
            'max_attempts' => (int) env('ARBOR_SYNC_MAX_ATTEMPTS', 10),
            'retry_delays_seconds' => array_map(
                static fn ($v) => (int) trim($v),
                explode(',', (string) env('ARBOR_SYNC_RETRY_DELAYS', '60,180,600,1800,3600,7200,14400,21600,28800,43200'))
            ),
            'retry_jitter_percent' => (float) env('ARBOR_SYNC_RETRY_JITTER_PERCENT', 0.15),
        ],
    ],

    'epraise' => [
        'school_id' => env('EPRAISE_SCHOOL_ID'),
        'api_key'   => env('EPRAISE_API_KEY'),
        'endpoints' => [
            'interventions' => env('EPRAISE_INTERVENTION_QUERY'),
            'demerits'      => env('EPRAISE_DEMERIT_QUERY'),
            'points'        => env('EPRAISE_POINT_QUERY'),
            'classes'      => env('EPRAISE_CLASSES_QUERY'),
            'teachers'        => env('EPRAISE_TEACHER_QUERY'),
            'students'      => env('EPRAISE_STUDENT_QUERY'),
        ],
    ],

    'behaviour_feeds' => [
        'staff' => [
            'url'      => env('BEHAVIOUR_FEED_STAFF_URL'),
            'password' => env('BEHAVIOUR_FEED_STAFF_PASSWORD'),
        ],
        'tutors' => [
            'url'      => env('BEHAVIOUR_FEED_TUTOR_HOY_URL'),
            'password' => env('BEHAVIOUR_FEED_TUTOR_HOY_PASSWORD'),
        ],
        'parents' => [
            'url'      => env('BEHAVIOUR_FEED_PARENTS_URL'),
            'password' => env('BEHAVIOUR_FEED_PARENTS_PASSWORD'),
        ],

        'timeout' => (int) env('BEHAVIOUR_FEED_TIMEOUT', 30),
    ],

];
