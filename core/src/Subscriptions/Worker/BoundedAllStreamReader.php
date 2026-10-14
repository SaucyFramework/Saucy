<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

use Generator;
use Saucy\MessageStorage\AllStreamQuery;
use Saucy\MessageStorage\AllStreamReader;

/**
 * Hides everything past the head watcher's safe head from a subscription's poll.
 *
 * `AllStreamSubscription::poll()` reads every event after its checkpoint and, on a poll that handled
 * nothing, stores `maxEventId()` as the new checkpoint. Either would carry the checkpoint across a
 * hole whose transaction has not committed yet. Capping both at the safe head keeps the checkpoint
 * at or below the first hole, so the event that later fills it is still ahead of the checkpoint.
 */
final readonly class BoundedAllStreamReader implements AllStreamReader
{
    public function __construct(
        private AllStreamReader $inner,
        private PositionCap $cap,
    ) {}

    public function paginate(AllStreamQuery $streamQuery): Generator
    {
        $cap = $this->cap->position;

        foreach ($this->inner->paginate($streamQuery) as $key => $storedEvent) {
            if ($storedEvent->globalPosition > $cap) {
                return;
            }
            yield $key => $storedEvent;
        }
    }

    public function maxEventId(): int
    {
        return min($this->cap->position, $this->inner->maxEventId());
    }
}
