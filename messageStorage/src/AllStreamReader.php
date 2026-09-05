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
     * The highest global position a reader may consume and checkpoint to right now.
     *
     * `global_position` is reserved at INSERT time but only becomes visible at COMMIT, so a
     * lower position can appear after a higher one. A reader that trusts {@see maxEventId()}
     * would skip such a row forever. This returns a ceiling that is safe to consume up to and
     * including, given the assumption that no insert stays uncommitted longer than the caller's
     * grace window.
     *
     * Two assumptions, both of which the caller owns:
     *
     *  1. No insert stays uncommitted for longer than the caller's grace window.
     *  2. `created_at` is a faithful proxy for INSERT time. It is stamped from the APP clock when
     *     the event is persisted, so app clocks must agree to within the grace window, and
     *     nothing may backdate `created_at` while the store is taking live writes: a backdated
     *     row sitting above an in-flight hole makes every row look settled and the hole is
     *     skipped. Run backdated imports and seeders against a store that is not taking live
     *     writes, or with the guard disabled.
     *
     * @param \DateTimeInterface $committedBefore rows created at or before this instant are
     *        considered settled; anything newer may still have a sibling transaction in flight.
     */
    public function safeCeiling(\DateTimeInterface $committedBefore): int;
}
