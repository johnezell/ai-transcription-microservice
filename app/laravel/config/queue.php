<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'sync'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        /*
        |--------------------------------------------------------------------------
        | Database Queue Driver
        |--------------------------------------------------------------------------
        |
        | Here you may configure the database queue driver. This driver keeps
        | track of all your queued jobs inside of a database table. You may
        | configure the table, queue, and retry time for this driver.
        |
        */

        'database' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'default',
            'retry_after' => 90,
            'after_commit' => false,
        ],

        /*
        |--------------------------------------------------------------------------
        | Redis Queue Driver
        |--------------------------------------------------------------------------
        |
        | Here you may configure the Redis queue driver. This driver is based
        | on the Redis list data structure, and provides a robust queue
        | solution for production applications. You may configure the
        | connection, queue, and retry time for this driver.
        |
        */

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => 90,
            'block_for' => null,
            'after_commit' => false,
        ],
        
        'redis-audio' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'audio-extraction',
            'retry_after' => 360, // Long timeout for audio jobs
        ],
        
        'redis-transcription' => [
            'driver' => 'redis',
            'connection' => 'default',
            'queue' => 'transcription',
            'retry_after' => 360,
        ],

        'batch' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'batch'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 300),
            'after_commit' => false,
        ],

        'audio_tests' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'audio_tests'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 180),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('TF_AWS_ACCESS_KEY_ID', env('AWS_ACCESS_KEY_ID')),
            'secret' => env('TF_SECRET_ACCESS_KEY', env('AWS_SECRET_ACCESS_KEY')),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        // Audio extraction priority queues
        'audio-extraction-high' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'audio-extraction-high',
            'retry_after' => 360,
            'after_commit' => false,
        ],

        'audio-extraction-low' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'audio-extraction-low',
            'retry_after' => 360,
            'after_commit' => false,
        ],

        // Transcription priority queues
        'transcription-high' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'transcription-high',
            'retry_after' => 1800,
            'after_commit' => false,
        ],

        'transcription-low' => [
            'driver' => 'database',
            'table' => 'jobs',
            'queue' => 'transcription-low',
            'retry_after' => 1800,
            'after_commit' => false,
        ],

        // Priority database connections (single queue + priority system)
        'priority-audio-extraction' => [
            'driver' => 'priority-database',
            'table' => 'jobs',
            'queue' => 'audio-extraction',
            'retry_after' => 360,
            'after_commit' => false,
        ],

        'priority-transcription' => [
            'driver' => 'priority-database',
            'table' => 'jobs',
            'queue' => 'transcription',
            'retry_after' => 1800,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
