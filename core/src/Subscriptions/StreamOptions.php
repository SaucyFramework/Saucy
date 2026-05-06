<?php

namespace Saucy\Core\Subscriptions;

final readonly class StreamOptions
{
    /**
     * @param array<string>|null $eventTypes Subscription's interest list.
     *        Filtering happens at delivery time (in PHP) so gap detection
     *        can observe the unfiltered global_position sequence.
     * @param int $gapTimeoutSeconds How long an unresolved gap stays in the
     *        gap registry before it's declared a permanently rolled-back
     *        write and forgotten. Pick a value comfortably above your
     *        worst-case write commit window.
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
        public int $gapTimeoutSeconds = 60,
    ) {}
}
