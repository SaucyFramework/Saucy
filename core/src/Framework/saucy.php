<?php

return [
    // directories to search in for commands, aggregates, events, queries, etc.
    'directories' => [
        __DIR__ . '/../app'
    ],

    'all_stream_projection' => [
        'timeout' => 20, // seconds,
        'queue' => 'projections',
        'keep_processing_without_new_messages_before_stop_in_seconds' => 5,
    ],

    'stream_projection' => [
        'timeout' => 20, // seconds,
        'queue' => 'projections',
        'keep_processing_without_new_messages_before_stop_in_seconds' => 5,
    ],
];
