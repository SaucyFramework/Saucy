<?php

namespace Saucy\Core\Subscriptions;

use DateInterval;

final readonly class StreamOptions
{
    /**
     * @param array<string>|null $eventTypes
     * @param int $visibilityDelayMs When > 0, the all-stream subscription
     *        will only deliver events whose `created_at` is older than this
     *        many milliseconds, and will only advance the empty-poll
     *        checkpoint to a position whose row passes the same filter.
     *        This is the guard against the auto-increment commit-order gap:
     *        an in-flight transaction can reserve a global_position that
     *        becomes visible well after a poll has already advanced past
     *        it. Recommended for all-stream subscriptions feeding any
     *        side-effect or fact table where missed events matter. Typical
     *        values: 500–5000ms — pick a value that comfortably exceeds
     *        your worst-case insert commit latency. Requires `created_at`
     *        to have at least millisecond precision (see migration
     *        `0000_00_00_000011_bump_event_store_created_at_precision`).
     */
    public function __construct(
        public int $pageSize = 100,
        public int $commitBatchSize = 10,
        public ?array $eventTypes = null,
        public int $startingFromPosition = 0,
        public ?int $processTimeoutInSeconds = null,
        public int $keepProcessingWithoutNewMessagesBeforeStopInSeconds = 5,
        public int $sleepWhenNoNewMessagesBeforeRetryInMicroseconds = 500_000, // 0.5 sec default
        public ?string $queue = null,
        public int $visibilityDelayMs = 0,
    ) {}
}
