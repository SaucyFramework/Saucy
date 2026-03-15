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
    ],

    'stream_projection' => [
        'timeout' => 20, // seconds,
        'queue' => 'projections',
        'keep_processing_without_new_messages_before_stop_in_seconds' => 5,
    ],

    'activity_log_retention_days' => null,

    'poison_messages' => [
        'notification' => [
            // Configure routes to receive notifications when messages are poisoned.
            // Each route specifies a notification channel and its target.
            // Example:
            //   ['channel' => 'mail', 'route' => 'ops@example.com'],
            //   ['channel' => 'slack', 'route' => 'https://hooks.slack.com/services/...'],
            'routes' => [],
        ],
    ],
];
