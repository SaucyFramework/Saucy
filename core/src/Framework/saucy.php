<?php

return [
    // directories to search in for commands, aggregates, events, queries, etc.
    'directories' => [
        __DIR__ . '/../app',
    ],

    'cache_path' => base_path('saucy/cache.dat'),

    'exclude_files' => ['*Test.php', '*/Tests/*', '*TestCase.php'],


    'all_stream_projection' => [
        'timeout' => 20, // seconds,
        'queue' => 'projections',
        'keep_processing_without_new_messages_before_stop_in_seconds' => 5,
        'commit_batch_size' => 1,
        'page_size' => 50,
        // How long an unresolved gap in the auto-increment sequence stays
        // tracked before it's declared a permanently rolled-back write
        // and forgotten. Set comfortably above your worst-case write
        // commit window. Default 60s.
        'gap_timeout_seconds' => 60,
    ],

    'stream_projection' => [
        'timeout' => 20, // seconds,
        'queue' => 'projections',
        'keep_processing_without_new_messages_before_stop_in_seconds' => 5,
    ],

    // When true, subscription triggering after event persist is deferred to a single queued job
    // instead of acquiring locks and dispatching jobs inline. Reduces DB queries in the request path.
    'defer_subscription_triggers' => false,

    'dynamodb' => [
        'prefix' => env('SAUCY_DYNAMODB_PREFIX', ''),
    ],

    'activity_log_retention_days' => null,

    'poison_messages' => [
        'notification' => [
            // Set to a notifiable class to receive notifications when messages are poisoned.
            // The class should use the Illuminate\Notifications\Notifiable trait.
            'notifiable' => null,
        ],
    ],
];
