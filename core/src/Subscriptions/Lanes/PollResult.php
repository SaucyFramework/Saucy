<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Lanes;

/**
 * Outcome of a single {@see LaneRunner::poll()}.
 *
 * `eventsRead === 0` alone is ambiguous: a poll that ran out of time budget before its first
 * event also read nothing, and treating that as "idle" would let the poll job start its
 * keep-alive countdown while the lane is in fact saturated.
 */
final readonly class PollResult
{
    public function __construct(
        public int $eventsRead,
        public bool $timedOut,
    ) {}

    public function isIdle(): bool
    {
        return $this->eventsRead === 0 && !$this->timedOut;
    }
}
