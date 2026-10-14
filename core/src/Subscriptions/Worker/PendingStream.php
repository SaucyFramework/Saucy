<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscription;

/**
 * One aggregate stream with new events for a per-aggregate projector.
 *
 * Every worker records every trigger it sees, not only its own, and keeps it for a while. If the
 * owner dies before handling it, the trigger belongs to someone else once the member list changes,
 * and that worker still has it.
 */
final class PendingStream
{
    public float $retryAt = 0.0;

    public int $failures = 0;

    /** Highest stream position seen for this stream; handled once the checkpoint reaches it. */
    public int $upTo = 0;

    public function __construct(
        public readonly string $key,
        public readonly StreamSubscription $subscription,
        public readonly AggregateStreamName $streamName,
        public readonly float $since,
        public readonly float $seenAt,
    ) {}
}
