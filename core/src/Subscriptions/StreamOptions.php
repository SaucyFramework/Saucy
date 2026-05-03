<?php

namespace Saucy\Core\Subscriptions;

use DateInterval;

final readonly class StreamOptions
{
    /**
     * @param array<string>|null $eventTypes
     * @param int $visibilityDelaySeconds When > 0, the all-stream subscription
     *        will only deliver events whose `created_at` is older than this
     *        many seconds, and will only advance the empty-poll checkpoint
     *        to a position whose row passes the same filter. This is the
     *        guard against the auto-increment commit-order gap: an in-flight
     *        transaction can reserve a global_position that becomes visible
     *        well after a poll has already advanced past it. Recommended for
     *        all-stream subscriptions feeding any side-effect or fact table
     *        where missed events matter. 1–5 seconds is usually enough; pick
     *        a value that comfortably exceeds your worst-case insert commit
     *        latency.
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
        public int $visibilityDelaySeconds = 0,
    ) {}
}
