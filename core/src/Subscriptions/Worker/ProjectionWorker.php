<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscription;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointNotFound;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Infra\RunningProcessLeases;
use Saucy\Core\Subscriptions\Infra\StartProcessException;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumerThatHandlesBatches;
use Saucy\Core\Subscriptions\PoisonMessages\FailureMode;
use Saucy\Core\Subscriptions\Metrics\NoOpLogger;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Ulid;

/**
 * Runs Saucy projectors and reactors in one long-lived process instead of a queue job per event.
 *
 * Each tick:
 *   1. Coordination: heartbeat, read the member list and the deploy gate. Each subscription belongs
 *      to one live worker (RendezvousAssignment), so workers split the work without talking.
 *   2. The head watcher reports which event types arrived, up to the first hole in `global_position`.
 *   3. Owned subscriptions that consume one of those types are polled through Saucy's own
 *      `AllStreamSubscription::poll()`, capped at that safe head. Per-aggregate projectors are polled
 *      per stream, like StreamTriggerJob does.
 *
 * Locks are Saucy's (`RunningProcesses`), so queue jobs (`SAUCY_TRIGGER_VIA_QUEUE`), inline
 * `awaitProjection` runs, the dashboard and `saucy:replay` all see the worker as a running process
 * and never process a subscription at the same time. The worker keeps a lock as a short lease that
 * it renews while busy and gives back after `leaseLingerSeconds` idle, instead of taking and dropping
 * it for every event.
 */
final class ProjectionWorker
{
    public readonly string $workerId;

    private readonly PositionCap $cap;

    /** @var array<string, AllStreamSubscription> subscription id => copy that reads through the cap */
    private array $subscriptions = [];

    /** @var array<string, SubscriptionState> owned all-stream subscriptions */
    private array $states = [];

    /** @var array<string, PendingStream> stream id => trigger, owned or not */
    private array $pendingStreams = [];

    /** @var list<string> */
    private array $members = [];

    /** @var list<WorkerInfo> */
    private array $liveWorkers = [];

    private ?string $gateSha = null;

    /** When the deploy gate was paused, while it is; null when it isn't paused. */
    private ?float $gatePausedSince = null;

    /** The head recorded by the deploy pause this worker started after (see boot()). */
    private ?int $lagFrom = null;

    private bool $admitted = true;

    private ?string $runnerSignature = null;

    /** @var array<string, float> subscription id => when it was first seen switched to the queue */
    private array $queueSince = [];

    private bool $stopping = false;

    private int $bootHead = 0;

    private float $startedAt = 0.0;

    private float $nextHeartbeatAt = 0.0;

    private float $nextGateCheckAt = 0.0;

    private float $nextSweepAt = 0.0;

    private float $nextMetricsAt = 0.0;

    private float $nextLateFillCheckAt = 0.0;

    private int $eventsHandled = 0;

    /** @var array<string, int> counters since the last metrics line */
    private array $counters = ['EventsHandled' => 0, 'SkippedGaps' => 0, 'GapTimeouts' => 0, 'GapProbes' => 0, 'LateGapFills' => 0, 'FailedPolls' => 0];

    private int $skippedGapsReported = 0;

    private int $gapTimeoutsReported = 0;

    private int $gapProbesReported = 0;

    /** @var \Closure(): float */
    private readonly \Closure $clock;

    /** @var \Closure(float): void */
    private readonly \Closure $sleep;

    /**
     * @param (\Closure(): void)|null $resetScope clears per-request state after each poll (Laravel's queue worker does the same between jobs)
     * @param (\Closure(\Throwable): void)|null $reportException
     * @param (\Closure(): float)|null $clock seconds
     * @param (\Closure(float): void)|null $sleep seconds
     */
    public function __construct(
        private readonly AllStreamSubscriptionRegistry $allStreamRegistry,
        private readonly StreamSubscriptionRegistry $streamRegistry,
        private readonly RunningProcesses&RunningProcessLeases $runningProcesses,
        private readonly WorkerCoordination $coordination,
        private readonly HeadWatcher $headWatcher,
        private readonly WorkerOptions $options,
        private readonly LoggerInterface $logger,
        private readonly ?WorkerMetrics $metrics = null,
        private readonly ?\Closure $resetScope = null,
        private readonly ?\Closure $reportException = null,
        ?\Closure $clock = null,
        ?\Closure $sleep = null,
        ?string $workerId = null,
        private readonly ?SubscriptionRunners $runners = null,
    ) {
        $this->workerId = $workerId ?? Ulid::generate();
        $this->cap = new PositionCap();
        $this->clock = $clock ?? static fn(): float => microtime(true);
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) ($seconds * 1_000_000));
        };
    }

    public function run(): void
    {
        $this->boot();

        try {
            while (!$this->shouldExit()) {
                $didWork = $this->tick();

                if ($this->options->healthFile !== null) {
                    @touch($this->options->healthFile);
                }

                if (!$didWork && !$this->stopping) {
                    ($this->sleep)($this->options->headPollIntervalSeconds);
                }
            }
        } finally {
            $this->shutdown();
        }
    }

    /**
     * Ask the loop to finish the poll it is in and exit (SIGTERM).
     */
    public function stop(): void
    {
        $this->stopping = true;
    }

    public function boot(): void
    {
        $now = ($this->clock)();
        $this->startedAt = $now;

        // A deploy paused every worker; triggers recorded since then were seen by nobody who could
        // act on them. Look back to the pause (a recent one only: a scale-out hours later must not
        // replay the whole deploy window).
        $gate = $this->coordination->gate();
        $rewindTo = $gate !== null
            && $gate->pausedAt !== null
            && ($this->options->sha === null || $gate->admits($this->options->sha))
            && time() - ($gate->pausedAtTime ?? 0) < 1800
                ? $gate->pausedAt
                : null;
        $this->bootHead = $this->headWatcher->boot($rewindTo);
        // Events recorded after the deploy paused the workers really waited: count them as lag, unlike
        // the rest of the history the boot look-back re-reads.
        $this->lagFrom = $rewindTo;
        $this->cap->position = $this->headWatcher->safeHead();

        foreach ($this->allStreamRegistry->streams as $subscriptionId => $subscription) {
            if ($this->options->runs($subscriptionId)) {
                $this->subscriptions[$subscriptionId] = $this->bounded($subscription);
            }
        }

        $this->nextSweepAt = $now + $this->options->sweepIntervalSeconds;
        $this->nextMetricsAt = $now + $this->options->metricsIntervalSeconds;
        $this->nextLateFillCheckAt = $now + $this->options->lateFillCheckIntervalSeconds;

        $this->coordinate($now);

        $this->logger->info('Saucy worker started', [
            'worker_id' => $this->workerId,
            'sha' => $this->options->sha,
            'subscriptions' => count($this->subscriptions),
            'owned' => count($this->states),
            'head' => $this->bootHead,
        ]);
    }

    /**
     * One loop iteration. Returns whether it polled anything, so the loop only sleeps when idle.
     */
    public function tick(): bool
    {
        $now = ($this->clock)();
        $this->coordinate($now);

        if (!$this->admitted) {
            $this->releaseAll();
            $this->emitMetrics($now);

            return false;
        }

        $this->followHead($now);

        if ($now >= $this->nextSweepAt) {
            $this->sweep();
            $this->nextSweepAt = $now + $this->options->sweepIntervalSeconds;
        }

        if ($now >= $this->nextLateFillCheckAt) {
            $this->reportLateFills();
            $this->nextLateFillCheckAt = $now + $this->options->lateFillCheckIntervalSeconds;
        }

        $this->maintainLeases($now);

        $didWork = $this->processSubscriptions();

        if ($this->options->streamSubscriptions) {
            $didWork = $this->processStreams() || $didWork;
        }

        $this->emitMetrics(($this->clock)());

        return $didWork;
    }

    public function shutdown(): void
    {
        $this->stopping = true;

        // Hand over cleanly: triggers this worker owns would otherwise wait for its heartbeat to
        // expire before another worker takes them.
        if ($this->admitted && $this->options->streamSubscriptions) {
            try {
                $this->processStreams(ignoreStopping: true);
            } catch (\Throwable $e) {
                $this->report($e);
            }
        }

        $this->releaseAll();

        try {
            $this->coordination->leave($this->workerId);
        } catch (\Throwable $e) {
            $this->report($e);
        }

        $this->logger->info('Saucy worker stopped', [
            'worker_id' => $this->workerId,
            'events_handled' => $this->eventsHandled,
            'run_seconds' => round(($this->clock)() - $this->startedAt, 1),
        ]);
    }

    /**
     * @return list<string> subscription ids this worker owns right now
     */
    public function ownedSubscriptions(): array
    {
        return array_keys($this->states);
    }

    public function eventsHandled(): int
    {
        return $this->eventsHandled;
    }

    private function shouldExit(): bool
    {
        if ($this->stopping) {
            return true;
        }

        $now = ($this->clock)();
        if ($this->options->maxSeconds > 0 && $now - $this->startedAt >= $this->options->maxSeconds) {
            return true;
        }

        if ($this->options->maxEvents > 0 && $this->eventsHandled >= $this->options->maxEvents) {
            return true;
        }

        return $this->options->memoryLimitMb > 0
            && memory_get_usage(true) / 1024 / 1024 >= $this->options->memoryLimitMb;
    }

    private function coordinate(float $now): void
    {
        $reassign = false;

        if ($now >= $this->nextHeartbeatAt) {
            $this->coordination->heartbeat(new WorkerInfo(
                id: $this->workerId,
                sha: $this->options->sha,
                expiresAt: (int) $now + $this->options->heartbeatTtlSeconds,
            ));
            $this->liveWorkers = $this->coordination->liveWorkers((int) $now);
            $this->nextHeartbeatAt = $now + $this->options->heartbeatIntervalSeconds;
            $reassign = true;
        }

        if ($now >= $this->nextGateCheckAt) {
            $gate = $this->coordination->gate();
            $this->nextGateCheckAt = $now + $this->options->gateCheckIntervalSeconds;

            if ($this->runners !== null) {
                $signature = serialize($this->runners->overrides());
                if ($signature !== $this->runnerSignature) {
                    $this->runnerSignature = $signature;
                    $reassign = true;
                }
            }

            // A worker built without a SHA (local dev) is not part of the deploy protocol.
            $admitted = $gate === null || $this->options->sha === null || $gate->admits($this->options->sha);
            $this->gatePausedSince = $gate !== null && $gate->paused
                ? (float) ($gate->pausedAtTime ?? ($this->gatePausedSince ?? $now))
                : null;

            if ($admitted && $gate !== null && $gate->paused && $this->options->sha !== null) {
                if ($this->coordination->liftPause($this->options->sha)) {
                    $this->logger->info('Saucy worker lifted the deploy pause', ['sha' => $this->options->sha]);
                }
            }

            if ($admitted !== $this->admitted || $gate?->sha !== $this->gateSha) {
                if (!$admitted) {
                    $this->logger->info('Saucy worker is not on the deployed version; it takes no locks', [
                        'worker_sha' => $this->options->sha,
                        'deployed_sha' => $gate->sha,
                    ]);
                }
                $reassign = true;
            }

            $this->admitted = $admitted;
            $this->gateSha = $gate?->sha;
        }

        if ($reassign) {
            $this->reassign();
        }
    }

    private function reassign(): void
    {
        $members = [];
        foreach ($this->liveWorkers as $worker) {
            // Only workers on the deployed version get work; an old one still draining does not.
            if ($this->gateSha === null || $worker->sha === $this->gateSha) {
                $members[$worker->id] = true;
            }
        }

        if ($this->admitted) {
            $members[$this->workerId] = true;
        } else {
            unset($members[$this->workerId]);
        }

        $this->members = array_keys($members);
        sort($this->members);

        $now = ($this->clock)();
        $pendingUpTo = max($this->bootHead, $this->headWatcher->safeHead());

        foreach ($this->states as $subscriptionId => $state) {
            if (!$this->owns($subscriptionId) || $this->handedToQueue($subscriptionId, $now)) {
                $this->release($state);
                unset($this->states[$subscriptionId]);
            }
        }

        foreach (array_keys($this->subscriptions) as $subscriptionId) {
            if (isset($this->states[$subscriptionId]) || !$this->owns($subscriptionId) || !$this->runsHere($subscriptionId)) {
                continue;
            }
            // The previous owner may have left work behind: poll once to catch up from the checkpoint.
            $state = new SubscriptionState($subscriptionId);
            $state->markPending($pendingUpTo, $now);
            $this->states[$subscriptionId] = $state;
        }
    }

    private function owns(string $key): bool
    {
        return $this->admitted && RendezvousAssignment::owner($key, $this->members) === $this->workerId;
    }

    /**
     * Whether this worker runs `subscriptionId` at all: allowed by --only/--except, and set to the
     * worker runner (`saucy:runner`). Triggers already recorded are still finished after a switch
     * to the queue; the lock keeps that from overlapping a queue job.
     */
    private function runsHere(string $subscriptionId): bool
    {
        return $this->options->runs($subscriptionId)
            && ($this->runners === null || $this->runners->runsOnWorker($subscriptionId));
    }

    /**
     * Whether an owned subscription has left the worker. After a switch to the queue the worker
     * keeps running it for `runnerHandoverSeconds`: a process that still has the old runner cached
     * skips the queue trigger for that long, and its events must not be left to nobody.
     */
    private function handedToQueue(string $subscriptionId, float $now): bool
    {
        if ($this->runsHere($subscriptionId)) {
            unset($this->queueSince[$subscriptionId]);

            return false;
        }
        if (!$this->options->runs($subscriptionId)) {
            return true;
        }
        $this->queueSince[$subscriptionId] ??= $now;

        return $now - $this->queueSince[$subscriptionId] >= $this->options->runnerHandoverSeconds;
    }

    private function followHead(float $now): void
    {
        // A burst can fill several windows; catch up on them before polling.
        for ($i = 0; $i < 10; $i++) {
            $window = $this->headWatcher->poll();
            if ($window === null) {
                break;
            }
            $this->onWindow($window, $now);
            if (!$window->full) {
                break;
            }
        }

        $this->cap->position = $this->headWatcher->safeHead();

        $skipped = $this->headWatcher->skippedGapCount();
        $this->counters['SkippedGaps'] += $skipped - $this->skippedGapsReported;
        $this->skippedGapsReported = $skipped;
        $timedOut = $this->headWatcher->timedOutGapCount();
        $this->counters['GapTimeouts'] += $timedOut - $this->gapTimeoutsReported;
        $this->gapTimeoutsReported = $timedOut;
        $probes = $this->headWatcher->probeCount();
        $this->counters['GapProbes'] += $probes - $this->gapProbesReported;
        $this->gapProbesReported = $probes;

        // Triggers older than this belong to a worker that had plenty of time to handle them.
        $retention = $this->options->heartbeatTtlSeconds + 2 * $this->options->heartbeatIntervalSeconds + 30;
        foreach ($this->pendingStreams as $key => $pending) {
            if ($now - $pending->seenAt > $retention && !$this->owns($key)) {
                unset($this->pendingStreams[$key]);
            }
        }
    }

    private function onWindow(HeadWindow $window, float $now): void
    {
        // History re-read by the boot look-back is mostly handled already; counting it as lag would
        // raise the lag alarm and scale the service out on every deploy.
        $since = $window->oldestEventAt !== null && ($window->to > $this->bootHead || ($this->lagFrom !== null && $window->to > $this->lagFrom))
            ? min($now, (float) $window->oldestEventAt->format('U.u'))
            : $now;
        $types = array_flip($window->eventTypes);

        foreach ($this->states as $subscriptionId => $state) {
            $eventTypes = $this->subscriptions[$subscriptionId]->streamOptions->eventTypes;
            // Same rule as Saucy's trigger: a subscription without event types is not woken by
            // events (the sweep still polls it).
            if ($eventTypes === null) {
                continue;
            }
            foreach ($eventTypes as $eventType) {
                if (isset($types[$eventType])) {
                    $state->markPending($window->to, $since);
                    break;
                }
            }
        }

        if (!$this->options->streamSubscriptions) {
            return;
        }

        foreach ($window->aggregateStreams as $streamNameString => $streamEventTypes) {
            $streamName = AggregateStreamName::fromString($streamNameString);
            if (!$streamName instanceof AggregateStreamName) {
                continue;
            }
            foreach ($this->streamRegistry->getStreamsForAggregateType($streamName->aggregateRootType) as $subscription) {
                if ($this->handedToQueue($subscription->subscriptionId, $now)) {
                    continue;
                }
                $eventTypes = $subscription->streamOptions->eventTypes;
                if ($eventTypes === null || array_intersect($eventTypes, $streamEventTypes) === []) {
                    continue;
                }
                $key = $subscription->getId($streamName);
                $pending = $this->pendingStreams[$key] ??= new PendingStream($key, $subscription, $streamName, $since, $now);
                $pending->upTo = max($pending->upTo, $window->aggregateStreamPositions[$streamNameString] ?? PHP_INT_MAX);
            }
        }
    }

    private function sweep(): void
    {
        // Safety net for a trigger that got lost (a poll that stopped early on its timeout, a
        // subscription without event types): poll everything owned once.
        $now = ($this->clock)();
        foreach ($this->states as $state) {
            $state->markPending($this->headWatcher->safeHead(), $state->pendingSince ?? $now);
            $state->caughtUpTo = min($state->caughtUpTo, $this->headWatcher->safeHead() - 1);
        }
    }

    private function maintainLeases(float $now): void
    {
        foreach ($this->states as $subscriptionId => $state) {
            if ($this->runners !== null && $this->handedToQueue($subscriptionId, $now)) {
                $this->release($state);
                unset($this->states[$subscriptionId]);
                continue;
            }
            if ($state->processId === null) {
                continue;
            }

            if (!$state->isPending() && $now - $state->lastWorkAt >= $this->options->leaseLingerSeconds) {
                $this->release($state);
            } elseif ($state->leaseExpiresAt - $now < $this->options->leaseRenewBeforeSeconds) {
                $this->renew($state, $now);
            }
        }
    }

    private function processSubscriptions(): bool
    {
        $now = ($this->clock)();
        $due = array_filter(
            $this->states,
            fn(SubscriptionState $state) => $state->isPending()
                && $this->cap->position > $state->caughtUpTo
                && $state->retryAt <= $now,
        );

        // Longest-waiting first, so one busy subscription cannot starve the others.
        uasort($due, fn(SubscriptionState $a, SubscriptionState $b) => ($a->pendingSince ?? $now) <=> ($b->pendingSince ?? $now));

        $didWork = false;
        foreach ($due as $state) {
            if ($this->stopping) {
                break;
            }

            $now = ($this->clock)();
            if (!$this->acquire($state, $now)) {
                continue;
            }

            if ($now - $state->lastPauseCheckAt >= $this->options->pauseCheckIntervalSeconds) {
                $state->lastPauseCheckAt = $now;
                if ($this->runningProcesses->isPaused($state->subscriptionId)) {
                    $this->release($state);
                    $state->retryAt = $now + $this->options->pauseCheckIntervalSeconds;
                    continue;
                }
            }

            $this->poll($state);
            $didWork = true;
        }

        return $didWork;
    }

    private function poll(SubscriptionState $state): void
    {
        $subscription = $this->subscriptions[$state->subscriptionId];
        $limit = $subscription->messageConsumer instanceof MessageConsumerThatHandlesBatches
            ? $subscription->messageConsumer->getBatchSize()
            : $subscription->streamOptions->pageSize;
        $cap = $this->cap->position;
        $startedAt = ($this->clock)();
        $handledTotal = 0;

        try {
            do {
                $now = ($this->clock)();
                if ($state->leaseExpiresAt - $now < $this->options->leaseRenewBeforeSeconds && !$this->renew($state, $now)) {
                    return;
                }
                $timeout = max(1, (int) floor($state->leaseExpiresAt - $now - 5));
                $handled = $subscription->poll($timeout);
                $handledTotal += $handled;
            } while ($handled >= $limit && ($this->clock)() - $startedAt < $this->options->sliceSeconds && !$this->stopping);

            if ($handled < $limit) {
                $state->caughtUp($cap);
            }
            $state->failures = 0;
        } catch (\Throwable $e) {
            $this->failed($state->subscriptionId, $e, ++$state->failures, $state->retryAt);
            $this->release($state);
        } finally {
            $state->lastWorkAt = ($this->clock)();
            $this->handled($handledTotal);
        }
    }

    private function acquire(SubscriptionState $state, float $now): bool
    {
        if ($state->processId !== null) {
            if ($state->leaseExpiresAt - $now >= $this->options->leaseRenewBeforeSeconds || $this->renew($state, $now)) {
                return true;
            }
        }

        $processId = Ulid::generate();
        $expiresAt = $now + $this->options->leaseTtlSeconds;

        if (!$this->start($state->subscriptionId, $processId, $now)) {
            // Held by a queue job, an inline awaitProjection run, a replay or a worker that has not
            // seen the new member list yet; or paused.
            $state->retryAt = $now + 1;

            return false;
        }

        $state->processId = $processId;
        $state->leaseExpiresAt = $expiresAt;
        $state->lastPauseCheckAt = $now; // start() refuses a paused subscription

        $this->runningProcesses->renew($state->subscriptionId, $processId, $this->dateTime($expiresAt), [
            'status' => 'running',
            'last_status_change_at' => $this->dateTime($now)->format('c'),
            'worker_id' => $this->workerId,
            'worker_sha' => $this->options->sha ?? 'unknown',
        ]);

        return true;
    }

    /**
     * Takes Saucy's lock. If it is held but expired, as left behind by a worker that crashed, it is
     * taken over after `staleLockGraceSeconds`. Saucy's own start() waits 30 s past expiry, which
     * would double the failover time after a crash.
     */
    private function start(string $key, string $processId, float $now): bool
    {
        $expiresAt = $this->dateTime($now + $this->options->leaseTtlSeconds);
        try {
            $this->runningProcesses->start($key, $processId, $expiresAt);

            return true;
        } catch (StartProcessException) {
        }

        if (!$this->runningProcesses->takeOverExpired($key, $this->dateTime($now - $this->options->staleLockGraceSeconds))) {
            return false;
        }
        $this->logger->info('Saucy worker took over an expired lock', ['subscription' => $key, 'worker_id' => $this->workerId]);

        try {
            $this->runningProcesses->start($key, $processId, $expiresAt);

            return true;
        } catch (StartProcessException) {
            return false;
        }
    }

    private function streamCheckpoint(PendingStream $pending): int
    {
        try {
            return $pending->subscription->checkpointStore->get($pending->key)->position;
        } catch (CheckpointNotFound) {
            return -1;
        }
    }

    private function renew(SubscriptionState $state, float $now): bool
    {
        if ($state->processId === null) {
            return false;
        }

        $expiresAt = $now + $this->options->leaseTtlSeconds;
        if ($this->runningProcesses->renew($state->subscriptionId, $state->processId, $this->dateTime($expiresAt))) {
            $state->leaseExpiresAt = $expiresAt;

            return true;
        }

        $this->logger->warning('Saucy worker lost a lease', [
            'subscription' => $state->subscriptionId,
            'worker_id' => $this->workerId,
        ]);
        $state->processId = null;

        return false;
    }

    private function release(SubscriptionState $state): void
    {
        if ($state->processId === null) {
            return;
        }

        $processId = $state->processId;
        $state->processId = null;

        try {
            $this->runningProcesses->stop($processId);
        } catch (\Throwable $e) {
            $this->report($e);
        }
    }

    private function releaseAll(): void
    {
        foreach ($this->states as $state) {
            $this->release($state);
        }
    }

    private function processStreams(bool $ignoreStopping = false): bool
    {
        $didWork = false;

        foreach ($this->pendingStreams as $key => $pending) {
            if ($this->stopping && !$ignoreStopping) {
                break;
            }

            $now = ($this->clock)();
            if ($pending->retryAt > $now || !$this->owns($key)) {
                continue;
            }

            // Already handled (by an inline awaitProjection run, the queue path, or before a restart):
            // one checkpoint read instead of a lock round trip.
            if ($pending->upTo !== PHP_INT_MAX && $this->streamCheckpoint($pending) >= $pending->upTo) {
                unset($this->pendingStreams[$key]);
                continue;
            }

            $processId = Ulid::generate();
            if (!$this->start($key, $processId, $now)) {
                $pending->retryAt = $now + 1;
                continue;
            }

            $didWork = true;
            $handledTotal = 0;
            try {
                do {
                    $handled = $pending->subscription->poll($pending->streamName);
                    $handledTotal += $handled;
                } while ($handled > 0 && ($this->clock)() - $now < $this->options->sliceSeconds);

                if ($handled === 0) {
                    unset($this->pendingStreams[$key]);
                }
            } catch (\Throwable $e) {
                $this->failed($key, $e, ++$pending->failures, $pending->retryAt);
            } finally {
                try {
                    $this->runningProcesses->stop($processId);
                } catch (\Throwable $e) {
                    $this->report($e);
                }
                $this->handled($handledTotal);
            }
        }

        return $didWork;
    }

    private function failed(string $key, \Throwable $e, int $failures, float &$retryAt): void
    {
        // Saucy's EventHandlerWithRetry already retried the event in-line for up to 60 s before the
        // poll gave up, and that stalls every subscription on this worker. Back off far enough
        // (2 s, 4 s, ... up to 15 min) that a poison message costs a few percent of the worker
        // instead of half of it.
        $retryAt = ($this->clock)() + min(900, 2 ** min($failures, 10));
        $this->counters['FailedPolls']++;
        $this->logger->error('Saucy worker poll failed', [
            'subscription' => $key,
            'failures' => $failures,
            'error' => $e->getMessage(),
        ]);
        $this->report($e);
    }

    private function handled(int $events): void
    {
        $this->eventsHandled += $events;
        $this->counters['EventsHandled'] += $events;

        if ($this->resetScope !== null) {
            ($this->resetScope)();
        }
    }

    private function reportLateFills(): void
    {
        foreach ($this->headWatcher->lateFills() as $fill) {
            $this->counters['LateGapFills']++;

            $affected = [];
            foreach ($this->subscriptions as $subscriptionId => $subscription) {
                if (in_array($fill['eventType'], $subscription->streamOptions->eventTypes ?? [], true)) {
                    $affected[] = $subscriptionId;
                }
            }

            // Per-aggregate projectors still get it: they read by stream position, and the next
            // event on that stream (or this trigger) picks it up.
            if ($this->options->streamSubscriptions && str_contains($fill['streamName'], '###')) {
                $this->onWindow(new HeadWindow(
                    from: $fill['position'] - 1,
                    to: $fill['position'],
                    eventTypes: [],
                    aggregateStreams: [$fill['streamName'] => [$fill['eventType']]],
                    oldestEventAt: null,
                    full: false,
                ), ($this->clock)());
            }

            $this->logger->critical('Saucy worker: an event committed after the head watcher skipped its gap; all-stream subscriptions that consume it missed it', [
                'global_position' => $fill['position'],
                'event_type' => $fill['eventType'],
                'stream_name' => $fill['streamName'],
                'subscriptions' => $affected,
            ]);
        }
    }

    private function emitMetrics(float $now): void
    {
        if ($this->metrics === null || $now < $this->nextMetricsAt) {
            return;
        }
        $this->nextMetricsAt = $now + $this->options->metricsIntervalSeconds;

        $lag = 0.0;
        $pending = 0;
        $held = 0;
        foreach ($this->states as $state) {
            if ($state->processId !== null) {
                $held++;
            }
            if ($state->isPending() && $state->pendingSince !== null) {
                $pending++;
                $lag = max($lag, $now - $state->pendingSince);
            }
        }
        foreach ($this->pendingStreams as $key => $stream) {
            if ($this->owns($key)) {
                $pending++;
                $lag = max($lag, $now - $stream->since);
            }
        }

        // While a deploy holds this worker paused, nothing it would run moves: report that as lag too,
        // instead of the 0 an idle worker would show.
        $gateHeld = $this->gatePausedSince !== null ? max(0.0, $now - $this->gatePausedSince) : 0.0;
        if (!$this->admitted) {
            $lag = max($lag, $gateHeld);
        }

        $this->metrics->emit($this->workerId, [
            'ProjectionLagSeconds' => round($lag, 3),
            'WorkerGateHeldSeconds' => round($gateHeld, 3),
            'PendingSubscriptions' => $pending,
            'HeldLeases' => $held,
            'OwnedSubscriptions' => count($this->states),
            'OpenGaps' => $this->headWatcher->openGapCount(),
            'OldestOpenGapSeconds' => round($this->headWatcher->oldestOpenGapAge(), 3),
        ] + $this->counters);

        $this->counters = array_map(static fn() => 0, $this->counters);
    }

    private function bounded(AllStreamSubscription $subscription): AllStreamSubscription
    {
        return new AllStreamSubscription(
            subscriptionId: $subscription->subscriptionId,
            streamOptions: $subscription->streamOptions,
            messageConsumer: $subscription->messageConsumer,
            eventReader: new BoundedAllStreamReader($subscription->eventReader, $this->cap),
            eventSerializer: $subscription->eventSerializer,
            checkpointStore: new NeverRewindCheckpointStore($subscription->checkpointStore),
            streamNameTypeMap: $subscription->streamNameTypeMap,
            activityStreamLogger: $this->options->activityLog ? $subscription->activityStreamLogger : new NoOpLogger(),
            failureMode: $subscription->failureMode,
            poisonMessageStore: $subscription->poisonMessageStore,
            poisonMessageRecorder: $subscription->poisonMessageRecorder,
            // A SkipMessage subscription drops the event for good once the retries run out, so a short
            // DB or API blip must not be enough: it keeps the queue's budget. Halt and PauseStream
            // subscriptions get the event again later, so they can fail fast.
            retryBudgetSeconds: $subscription->failureMode === FailureMode::SkipMessage
                ? $subscription->retryBudgetSeconds
                : ($this->options->retryBudgetSeconds ?? $subscription->retryBudgetSeconds),
        );
    }

    private function dateTime(float $timestamp): \DateTime
    {
        return (new \DateTime())->setTimestamp((int) ceil($timestamp));
    }

    private function report(\Throwable $e): void
    {
        if ($this->reportException !== null) {
            ($this->reportException)($e);
        }
    }
}
