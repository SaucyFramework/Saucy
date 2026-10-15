<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

/**
 * Where the worker reports its numbers every `metricsIntervalSeconds`: `ProjectionLagSeconds` (the
 * one to autoscale and alarm on), `WorkerGateHeldSeconds` (how long a deploy has held the workers
 * paused), `PendingSubscriptions`, `OwnedSubscriptions`, `HeldLeases`,
 * `EventsHandled`, `FailedPolls` and the gap counters `OpenGaps`, `OldestOpenGapSeconds`,
 * `SkippedGaps`, `GapTimeouts`, `GapProbes`, `LateGapFills`.
 */
interface WorkerMetrics
{
    /**
     * @param array<string, int|float> $values metric name => value; counters are since the last call
     */
    public function emit(string $workerId, array $values): void;
}
