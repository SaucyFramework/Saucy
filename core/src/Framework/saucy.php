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

    // When true, subscription triggering after event persist is deferred to a single queued job
    // instead of acquiring locks and dispatching jobs inline. Reduces DB queries in the request path.
    'defer_subscription_triggers' => false,

    'dynamodb' => [
        'prefix' => env('SAUCY_DYNAMODB_PREFIX', ''),
    ],

    'activity_log_retention_days' => null,

    // The long-running projection worker (`php artisan saucy:work`). Off by default: with it off,
    // nothing below is read and every subscription runs on the queue as before.
    'worker' => [
        'enabled' => (bool) env('SAUCY_WORKER_ENABLED', false),

        // Which runner a subscription uses unless `saucy:runner` overrides it (`queue` or `worker`).
        'default_runner' => env('SAUCY_DEFAULT_RUNNER', 'queue'),
        // Read `saucy:runner` overrides from the coordination store. That is one read per process per
        // runner_cache_seconds, not per event. Once every subscription has moved, set
        // SAUCY_DEFAULT_RUNNER=worker and SAUCY_RUNNER_OVERRIDES=false: no reads, and no trigger jobs.
        'runner_overrides' => (bool) env('SAUCY_RUNNER_OVERRIDES', true),
        'runner_cache_seconds' => (float) env('SAUCY_RUNNER_CACHE_SECONDS', 5),

        // The commit the worker was built from. A worker only takes locks when it matches the SHA
        // a deploy wrote with `saucy:worker-gate pause`. Unset: the worker ignores the gate.
        'sha' => env('SAUCY_WORKER_SHA'),

        // The head watcher reads the event-store head several times a second; point it at a read
        // replica. null = the default connection.
        'head_connection' => env('SAUCY_WORKER_HEAD_CONNECTION'),
        'head_poll_interval_ms' => (int) env('SAUCY_WORKER_HEAD_POLL_INTERVAL_MS', 150),

        // Holes in global_position: an uncommitted transaction, a rollback or a failed insert. With
        // the probe (MySQL only) a hole is checked on the primary with a `FOR SHARE NOWAIT` read:
        // still locked = an open transaction, so wait up to in_flight_timeout_seconds; not locked
        // and empty = it never fills, so skip it at once. Without the probe a hole is skipped after
        // gap_timeout_seconds.
        'gap_probe' => (bool) env('SAUCY_WORKER_GAP_PROBE', true),
        'gap_timeout_seconds' => (float) env('SAUCY_WORKER_GAP_TIMEOUT_SECONDS', 5),
        'in_flight_timeout_seconds' => (float) env('SAUCY_WORKER_IN_FLIGHT_TIMEOUT_SECONDS', 30),
        'window_size' => 1000,

        // On start a worker re-reads this much recent history (and back to a deploy pause), so it
        // sees per-aggregate triggers other workers kept for it.
        'boot_lookback_seconds' => 120,
        'boot_lookback' => 200000, // positions, at most

        'lease_ttl_seconds' => 30,
        'lease_renew_before_seconds' => 20,
        'lease_linger_seconds' => 15,
        // An expired lock (its holder crashed) is taken over this long after expiry.
        'stale_lock_grace_seconds' => 5,
        'pause_check_interval_seconds' => 2,
        'heartbeat_interval_seconds' => 5,
        'heartbeat_ttl_seconds' => 20,
        'gate_check_interval_seconds' => 1,
        'sweep_interval_seconds' => 300,
        'slice_seconds' => 2,

        // In-line retries of a failing event. One process runs every subscription, so a long
        // retry stalls all of them; the worker backs the subscription off instead.
        'retry_budget_seconds' => 5,

        // CloudWatch Embedded Metric Format lines on stdout every metrics_interval_seconds.
        'emf_metrics' => (bool) env('SAUCY_WORKER_EMF_METRICS', false),
        'metrics_interval_seconds' => 10,
        // Saucy writes 2-3 activity-log rows per poll.
        'activity_log' => (bool) env('SAUCY_WORKER_ACTIVITY_LOG', true),
    ],

    'poison_messages' => [
        // How long a failing event is retried in-line before it counts as poison. Queue jobs can
        // afford a minute; the worker uses its own (shorter) worker.retry_budget_seconds.
        'retry_budget_seconds' => 60,
        'notification' => [
            // Set to a notifiable class to receive notifications when messages are poisoned.
            // The class should use the Illuminate\Notifications\Notifiable trait.
            'notifiable' => null,
        ],
    ],
];
