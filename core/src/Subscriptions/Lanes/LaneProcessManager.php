<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Lanes;

use DateInterval;
use DateTime;
use EventSauce\BackOff\BackOffRunner;
use EventSauce\BackOff\LinearBackOffStrategy;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscription;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Infra\StartProcessException;
use Symfony\Component\Uid\Ulid;

/**
 * Starts and coordinates lane poll processes.
 *
 * The lane's own lease lives under `lane__<name>`; member leases stay under the member's own
 * subscription id, which is what lets a member be pulled out of the lane (catch-up, sync claim,
 * replay, hot swap) without the lane noticing anything beyond a membership bump.
 */
final readonly class LaneProcessManager
{
    /**
     * @param float $syncClaimTimeoutInSeconds how long an inline (sync) run waits for the lane to
     *        acknowledge its claim before giving up and handing the work to the lane.
     */
    public function __construct(
        private LaneRegistry $laneRegistry,
        private RunningProcesses $runningProcesses,
        private LaneCoordinator $coordinator,
        private float $syncClaimTimeoutInSeconds = 2.0,
    ) {}

    public function startLaneIfNotRunning(string $lane): void
    {
        $laneId = $this->laneRegistry->laneSubscriptionId($lane);

        if ($this->runningProcesses->isActive($laneId)) {
            return;
        }

        $config = $this->laneRegistry->lane($lane);
        $processId = Ulid::generate();

        try {
            $this->runningProcesses->start(
                subscriptionId: $laneId,
                processId: $processId,
                expiresAt: (new DateTime('now'))->add(new DateInterval("PT{$config->processTimeoutInSeconds}S")),
            );
        } catch (StartProcessException) {
            // Another process got there first.
            return;
        }

        self::dispatchPollJob($lane, $processId, $config);
    }

    /**
     * A lane job holds its lease for the whole run, so the worker must never time it out and
     * redeliver: two runners on one lane would both pass the `isActive(laneId, processId)`
     * check. `tries = 1` plus a worker timeout slightly beyond the lease is the guard; the
     * queue connection's `retry_after` (or the SQS visibility timeout) must also exceed it.
     */
    public static function dispatchPollJob(string $lane, string $processId, LaneConfig $config): void
    {
        $job = new LanePollJob($lane, $processId);
        $job->timeout = $config->processTimeoutInSeconds + 30;

        dispatch($job)->onQueue($config->queue);
    }

    public function startAllLanes(): void
    {
        foreach (array_keys($this->laneRegistry->lanes()) as $lane) {
            $this->startLaneIfNotRunning($lane);
        }
    }

    /**
     * @param array<string> $eventTypes
     */
    public function startLanesThatRequireEvents(array $eventTypes): void
    {
        foreach (array_keys($this->laneRegistry->lanes()) as $lane) {
            $laneTypes = $this->laneRegistry->eventTypesFor($lane);

            // A null union means a member subscribes to everything, so the lane always matches.
            if ($laneTypes !== null && count(array_intersect($laneTypes, $eventTypes)) === 0) {
                continue;
            }

            $this->startLaneIfNotRunning($lane);
        }
    }

    public function bumpMembershipFor(string $subscriptionId, bool $structural = true): void
    {
        $lane = $this->laneRegistry->laneFor($subscriptionId);

        if ($lane !== null) {
            $this->coordinator->bumpMembership($lane->name, $structural);
        }
    }

    /**
     * Pauses a member and waits until the lane has acknowledged that it is out, then takes the
     * member's own lease so nothing else can pick it up. Returns the process id the caller must
     * hand back to {@see releaseMember()} (or `null` when the member is not in a lane).
     *
     * If the lane never acknowledges, the pause this method took is undone before the exception
     * propagates: an operator action must never leave a projector paused forever.
     *
     * @throws \RuntimeException when the lane does not acknowledge in time
     */
    public function quiesceMember(string $memberId, string $reason): ?string
    {
        $lane = $this->laneRegistry->laneFor($memberId);

        if ($lane === null) {
            return null;
        }

        $pausedHere = false;
        if (!$this->runningProcesses->isPaused($memberId)) {
            $this->runningProcesses->pause($memberId, $reason);
            $pausedHere = true;
        }

        $version = $this->coordinator->bumpMembership($lane->name, structural: true);
        $laneId = $this->laneRegistry->laneSubscriptionId($lane->name);

        try {
            $this->awaitAcknowledgement($lane, $laneId, $version);

            $processId = Ulid::generate();
            $starter = new BackOffRunner(new LinearBackOffStrategy(500, 100), StartProcessException::class);
            $starter->run(function () use ($memberId, $processId, $lane): void {
                $this->runningProcesses->start(
                    subscriptionId: $memberId,
                    processId: $processId,
                    expiresAt: (new DateTime('now'))->add(new DateInterval("PT{$lane->processTimeoutInSeconds}S")),
                    ignorePaused: true,
                );
            });

            return $processId;
        } catch (\Throwable $e) {
            if ($pausedHere) {
                $this->runningProcesses->resume($memberId);
            }
            $this->coordinator->bumpMembership($lane->name, structural: true);

            throw $e;
        }
    }

    /**
     * A lane mid-page needs a moment to reach its next boundary, so this waits longer than the
     * sync-claim path does. It is bounded by the lane's `quiesce_wait_seconds` rather than by its
     * process timeout: replays and hot swaps are triggered from HTTP requests, and a wait longer
     * than the request's own limit would just be SIGKILLed - which is the one way the member
     * WOULD be left paused. Failing fast and letting the operator retry is safe, because the
     * throw undoes the pause.
     */
    private function awaitAcknowledgement(LaneConfig $lane, string $laneId, int $version): void
    {
        $deadline = time() + $lane->quiesceWaitInSeconds;

        while (true) {
            if ($this->coordinator->acknowledgedVersion($lane->name) >= $version) {
                return;
            }

            // The poll job stops dispatching 30 seconds before its lease expires, so an inactive
            // lane lease means no runner can still be mid-page for this member.
            if (!$this->runningProcesses->isActive($laneId)) {
                return;
            }

            if (time() >= $deadline) {
                throw new \RuntimeException(
                    "Lane '{$lane->name}' did not acknowledge membership version {$version} in time",
                );
            }

            usleep(250_000);
        }
    }

    /**
     * Undoes {@see quiesceMember()}: releases the lease, resumes the member and wakes the lane
     * so it re-evaluates its membership.
     */
    public function releaseMember(string $memberId, ?string $processId): void
    {
        $lane = $this->laneRegistry->laneFor($memberId);

        if ($this->runningProcesses->isPaused($memberId)) {
            $this->runningProcesses->resume($memberId);
        }

        if ($processId !== null) {
            $this->runningProcesses->stop($processId);
        }

        if ($lane === null) {
            return;
        }

        $this->coordinator->bumpMembership($lane->name, structural: true);
        $this->startLaneIfNotRunning($lane->name);
    }

    /**
     * The `awaitProjection` path: run one member inline, in the request, without the lane
     * double-handling the same events.
     *
     * The member is claimed on the coordinator; the lane re-reads the claimed set at its next
     * page boundary and treats claimed members as out-of-lane. Whenever this cannot be made
     * safe, the lane is started instead so the event is never left unprocessed.
     */
    public function runMemberInline(AllStreamSubscription $member): void
    {
        $memberId = $member->subscriptionId;
        $lane = $this->laneRegistry->laneFor($memberId);

        if ($lane === null) {
            return;
        }

        $laneName = $lane->name;
        $laneId = $this->laneRegistry->laneSubscriptionId($laneName);
        $processId = Ulid::generate();

        // The lease is taken BEFORE the claim, so a lane evaluating in this window sees the
        // member as lease-held and excludes it either way; and a claim whose owner died without
        // ever holding the lease is recognised as stale by LaneMembership::evaluate().
        try {
            $this->runningProcesses->start(
                subscriptionId: $memberId,
                processId: $processId,
                expiresAt: (new DateTime('now'))->add(new DateInterval("PT{$lane->processTimeoutInSeconds}S")),
            );
        } catch (StartProcessException) {
            // Somebody else owns the member; let the lane deal with the event.
            $this->startLaneIfNotRunning($laneName);
            return;
        }

        $version = $this->coordinator->claim($laneName, $memberId);

        // The poll job stops dispatching 30 seconds before its lease expires, so an inactive
        // lane lease means no runner can be mid-page and the inline run may start immediately.
        if ($this->runningProcesses->isActive($laneId) && !$this->waitForAcknowledgement($laneName, $laneId, $version)) {
            $this->coordinator->release($laneName, $memberId);
            $this->runningProcesses->stop($processId);
            $this->startLaneIfNotRunning($laneName);
            return;
        }

        try {
            $member->poll();
        } finally {
            $this->runningProcesses->stop($processId);
            $this->coordinator->release($laneName, $memberId);
        }
    }

    /**
     * Short linear back-off, capped so a request never blocks for long. Returns false when the
     * cap was hit, in which case the caller hands the work back to the lane.
     */
    private function waitForAcknowledgement(string $laneName, string $laneId, int $version): bool
    {
        $deadline = microtime(true) + $this->syncClaimTimeoutInSeconds;
        $delayMs = 25;

        while (true) {
            if ($this->coordinator->acknowledgedVersion($laneName) >= $version) {
                return true;
            }

            if (!$this->runningProcesses->isActive($laneId)) {
                return true;
            }

            if (microtime(true) >= $deadline) {
                return false;
            }

            usleep($delayMs * 1000);
            $delayMs = min($delayMs + 25, 250);
        }
    }
}
