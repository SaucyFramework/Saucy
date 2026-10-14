<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

/**
 * What a worker knows about one all-stream subscription it owns.
 */
final class SubscriptionState
{
    public ?string $processId = null;

    public float $leaseExpiresAt = 0.0;

    public float $lastWorkAt = 0.0;

    public float $lastPauseCheckAt = 0.0;

    /** Highest safe position known to hold an event this subscription consumes. */
    public int $pendingUpTo = 0;

    /** Safe head at the start of the last poll that drained the subscription. */
    public int $caughtUpTo = 0;

    /** When the oldest unhandled event was recorded; null when caught up. */
    public ?float $pendingSince = null;

    public float $retryAt = 0.0;

    public int $failures = 0;

    public function __construct(
        public readonly string $subscriptionId,
    ) {}

    public function markPending(int $upTo, float $since): void
    {
        $this->pendingUpTo = max($this->pendingUpTo, $upTo);
        $this->pendingSince = $this->pendingSince === null ? $since : min($this->pendingSince, $since);
    }

    public function isPending(): bool
    {
        return $this->pendingUpTo > $this->caughtUpTo;
    }

    public function caughtUp(int $position): void
    {
        $this->caughtUpTo = max($this->caughtUpTo, $position);
        if (!$this->isPending()) {
            $this->pendingSince = null;
        }
    }
}
