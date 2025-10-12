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
