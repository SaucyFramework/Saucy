<?php

namespace Saucy\MessageStorage;

use Generator;

interface AllStreamReader
{
    /**
     * @return Generator<int, StoredEvent>
     */
    public function paginate(AllStreamQuery $streamQuery): Generator;

    public function maxEventId(): int;

    /**
     * Returns the highest global_position whose row was inserted at least
     * `$visibilityDelayMs` milliseconds ago — i.e. the highest position
     * guaranteed to be safely past the auto-increment commit-order gap.
     * Used by the all-stream subscription to bound checkpoint advancement
     * on empty polls.
     *
     * Returns 0 when no rows pass the filter.
     */
    public function maxEventIdWithVisibilityDelay(int $visibilityDelayMs): int;
}
