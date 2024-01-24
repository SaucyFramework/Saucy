<?php

namespace Saucy\Core\Subscriptions;

final readonly class StreamOptions
{
    public function __construct(
        public int $pageSize,
        public int $commitBatchSize,
        public ?array $eventTypes = null,
        public int $startingFromPosition = 0,
    )
    {
    }
}
