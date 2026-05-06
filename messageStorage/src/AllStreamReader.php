<?php

namespace Saucy\MessageStorage;

use Generator;

interface AllStreamReader
{
    /**
     * Returns events from the all-stream with `global_position > fromPosition`,
     * ordered by `global_position` ascending, up to `limit` rows.
     *
     * Crucially, this method does NOT filter by event type — gap detection
     * needs to see the unfiltered global sequence. Subscription consumers
     * filter by event type at delivery time.
     *
     * @return Generator<int, StoredEvent>
     */
    public function paginate(AllStreamQuery $streamQuery): Generator;

    /**
     * Returns events at the given exact `global_position`s. Used by gap
     * tracking to re-check whether previously-missing positions have now
     * become visible (their writer transaction committed).
     *
     * @param array<int> $positions
     * @return Generator<int, StoredEvent>
     */
    public function fetchByGlobalPositions(array $positions): Generator;

    public function maxEventId(): int;
}
