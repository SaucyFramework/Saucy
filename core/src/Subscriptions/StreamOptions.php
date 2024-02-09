<?php

namespace Saucy\Core\Subscriptions;

use DateInterval;

final readonly class StreamOptions
{
    public function __construct(
        public int $pageSize = 100,
        public int $commitBatchSize = 10,
        public ?array $eventTypes = null,
        public int $startingFromPosition = 0,
        public ?int $processTimeoutInSeconds = null,
        public int $keepProcessingWithoutNewMessagesBeforeStopInSeconds = 5,
        public int $sleepWhenNoNewMessagesBeforeRetryInMicroseconds = 500_000, // 0.5 sec default
        public ?string $queue = null,
    )
    {
    }
}
