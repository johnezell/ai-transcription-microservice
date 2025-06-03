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

    // S3 Configuration - Now primary for TrueFire video access
    's3' => [
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
        'bucket' => env('AWS_BUCKET', 'tfstream'), // Default to tfstream bucket for TrueFire videos
        'default_expiration' => env('S3_DEFAULT_EXPIRATION', 604800), // 7 days (S3 presigned URL maximum)
    ],

    // CloudFront Configuration - DEPRECATED: No longer used for TrueFire video access
    // Switched to direct S3 access due to CloudFront 403 errors
    'cloudfront' => [
        'private_key_path' => env('CLOUDFRONT_PRIVATE_KEY_PATH', storage_path('app/cloudfront/pk-APKAJKYJ7CQO2ZKTVR4Q.pem')),
        'key_pair_id' => env('CLOUDFRONT_KEY_PAIR_ID', 'APKAJKYJ7CQO2ZKTVR4Q'),
        'region' => env('CLOUDFRONT_REGION', 'us-east-1'),
        'default_expiration' => env('CLOUDFRONT_DEFAULT_EXPIRATION', 86400),
        // NOTE: This configuration is kept for backward compatibility but is no longer actively used
    ],

];
