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

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'cloudfront' => [
        'private_key_path' => env('CLOUDFRONT_PRIVATE_KEY_PATH', storage_path('app/cloudfront/pk-APKAJKYJ7CQO2ZKTVR4Q.pem')),
        'key_pair_id' => env('CLOUDFRONT_KEY_PAIR_ID', 'APKAJKYJ7CQO2ZKTVR4Q'),
        'region' => env('CLOUDFRONT_REGION', 'us-east-1'),
        'default_expiration' => env('CLOUDFRONT_DEFAULT_EXPIRATION', 300),
    ],

];
