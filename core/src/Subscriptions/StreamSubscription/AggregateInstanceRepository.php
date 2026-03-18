<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\StreamSubscription;

use Generator;

interface AggregateInstanceRepository
{
    /**
     * Record an aggregate instance with its latest stream position.
     * Inserts on first event, updates stream_position on subsequent events.
     */
    public function record(string $aggregateType, string $aggregateId, int $streamPosition): void;

    /**
     * Get all known aggregate IDs for a given aggregate type.
     *
     * @return Generator<object{aggregateId: string, streamPosition: int}>
     */
    public function getAllForType(string $aggregateType): Generator;
}
