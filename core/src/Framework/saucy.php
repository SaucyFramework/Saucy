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

    // Projection lanes. Lanes are ENABLED when this array is non-empty; with it empty every
    // all-stream projector keeps its own poll job, lease and checkpoint exactly as before.
    // A lane is one long-lived poller that reads the all-stream once and dispatches each event
    // in memory to every member projector. Members keep their own checkpoints (same ids).
    // Every key below is optional; the values shown are the defaults.
    'lanes' => [
        // 'default' => [
        //     'queue' => null,               // queue the lane poll job runs on
        //     'page_size' => 100,            // events read per page
        //     'process_timeout' => 240,      // seconds a lease lives; the job self-chains before it
        //     'keep_alive_seconds' => 30,    // keep polling this long after the last empty poll
        //     'sleep_ms' => 250,             // sleep between empty polls while kept alive
        //     'catch_up_threshold' => 1000,  // members further behind than this run standalone
        //     'commit_batch_size' => null,   // null = page_size; money lanes want this small
        //     'retry_budget_seconds' => 10,  // per-event retry budget before it is poison
        //     'quiesce_wait_seconds' => 20,  // how long a replay/swap waits for the lane to yield
        // ],
    ],

    // Operator override: subscription id => lane name. Wins over the #[Projector(lane: ...)]
    // attribute.
    'lane_assignments' => [],

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
