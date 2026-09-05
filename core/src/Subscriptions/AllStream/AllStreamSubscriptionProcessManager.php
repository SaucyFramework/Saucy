<?php

namespace Saucy\Core\Subscriptions\AllStream;

use DateInterval;
use DateTime;
use EventSauce\BackOff\BackOffRunner;
use EventSauce\BackOff\LinearBackOffStrategy;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Infra\StartProcessException;
use Saucy\Core\Subscriptions\Lanes\LaneProcessManager;
use Saucy\Core\Subscriptions\Lanes\LaneRegistry;
use Saucy\Core\Subscriptions\RunAllSubscriptionsInSync;
use Symfony\Component\Uid\Ulid;

final readonly class AllStreamSubscriptionProcessManager
{
    private DateInterval $defaultProcessTimeout;

    public function __construct(
        private AllStreamSubscriptionRegistry $allStreamSubscriptionRegistry,
        private RunningProcesses $runningProcesses,
        private RunAllSubscriptionsInSync $runSync,
        ?DateInterval $defaultProcessTimeout = null,
        private ?LaneRegistry $laneRegistry = null,
        private ?LaneProcessManager $laneProcessManager = null,
    ) {
        $this->defaultProcessTimeout = $defaultProcessTimeout ?? new DateInterval('PT5M');
    }

    /**
     * Can be used to trigger all projections based on a cron schedule
     * @return void
     */
    public function startProcesses(): void
    {
        if ($this->lanesEnabled()) {
            $this->laneProcessManager->startAllLanes();

            // Subscriptions that belong to no lane (background replays, `replay__*`) still need
            // their own process; the cron is the only thing that starts them.
            foreach ($this->allStreamSubscriptionRegistry->streams as $stream) {
                if ($this->laneFor($stream->subscriptionId) === null) {
                    $this->startStreamIfNotRunning($stream);
                }
            }

            return;
        }

        // start all streams as processes
        foreach ($this->allStreamSubscriptionRegistry->streams as $stream) {
            $this->startStreamIfNotRunning($stream);
        }
    }

    /**
     * @param array<string> $eventTypes
     * @return void
     */
    public function startProcessesThatRequireEvents(array $eventTypes): void
    {
        $lanesToStart = [];

        // start all streams as processes
        foreach ($this->allStreamSubscriptionRegistry->streams as $stream) {
            if ($stream->streamOptions->eventTypes === null) {
                continue;
            }
            if (count(array_intersect($stream->streamOptions->eventTypes, $eventTypes)) === 0) {
                continue;
            }

            $lane = $this->laneFor($stream->subscriptionId);

            if ($lane === null) {
                $this->startStreamIfNotRunning($stream);
                continue;
            }

            // A projector marked run-in-sync (awaitProjection) must still run inline in the
            // request; it claims itself on the lane so the lane does not double-handle it.
            if ($this->runSync->isRunSync($stream->messageConsumer)) {
                $this->laneProcessManager?->runMemberInline($stream);
                continue;
            }

            $lanesToStart[$lane] = true;
        }

        foreach (array_keys($lanesToStart) as $lane) {
            $this->laneProcessManager?->startLaneIfNotRunning($lane);
        }
    }

    public function startProcess(string $name): void
    {
        $stream = $this->allStreamSubscriptionRegistry->get($name);
        $lane = $this->laneFor($stream->subscriptionId);

        if ($lane !== null) {
            // Wake the lane and make it re-evaluate, in case the member was excluded.
            $this->laneProcessManager?->bumpMembershipFor($stream->subscriptionId);
            $this->laneProcessManager?->startLaneIfNotRunning($lane);
            return;
        }

        $this->startStreamIfNotRunning($stream);
    }

    /**
     * Runs a single subscription on its own lease and its own poll job, bypassing any lane.
     * Used for lane members that are too far behind to catch up inside the lane.
     */
    public function startStandalone(string $name): void
    {
        $this->startStreamIfNotRunning($this->allStreamSubscriptionRegistry->get($name));
    }

    public function pause(string $name): void
    {
        $stream = $this->allStreamSubscriptionRegistry->get($name);
        $this->runningProcesses->pause($stream->subscriptionId, 'paused');
        $this->laneProcessManager?->bumpMembershipFor($stream->subscriptionId);
    }

    public function resume(string $name): void
    {
        $stream = $this->allStreamSubscriptionRegistry->get($name);
        $this->runningProcesses->resume($stream->subscriptionId);
        $this->laneProcessManager?->bumpMembershipFor($stream->subscriptionId);
    }

    public function replaySubscription(string $name): void
    {
        $stream = $this->allStreamSubscriptionRegistry->get($name);
        $lane = $this->laneFor($stream->subscriptionId);

        if ($lane !== null) {
            $processId = null;
            try {
                // Make sure the lane is not mid-page for this member before resetting its
                // checkpoint. The quiesce is INSIDE the try: if it throws (the lane never
                // acknowledged) the finally still resumes the member and wakes the lane, so a
                // failed replay can never leave a projector paused forever.
                $processId = $this->laneProcessManager?->quiesceMember($stream->subscriptionId, 'paused for replay');
                $stream->prepareForReplay();
            } finally {
                // The member is now far behind the window, so the lane hands it to a standalone
                // catch-up job and takes it back once that job stops inside the window.
                $this->laneProcessManager?->releaseMember($stream->subscriptionId, $processId);
            }
            return;
        }

        // pause other triggers of this process
        $this->runningProcesses->pause($stream->subscriptionId, 'paused for replay');

        // wait to obtain lock
        $processId = Ulid::generate();
        $runner = new BackOffRunner(new LinearBackOffStrategy(500, 100), StartProcessException::class);
        $runner->run(function () use ($stream, $processId) {
            $this->runningProcesses->start(
                subscriptionId: $stream->subscriptionId,
                processId: $processId,
                expiresAt: (new DateTime('now'))->add(new DateInterval("PT15M")),
                ignorePaused: true,
            );
        });

        $stream->prepareForReplay();
        $this->runningProcesses->resume($stream->subscriptionId);
        $this->runningProcesses->stop($processId);

        $this->startStreamIfNotRunning($stream);
    }

    /**
     * @phpstan-assert-if-true !null $this->laneProcessManager
     */
    private function lanesEnabled(): bool
    {
        return $this->laneRegistry?->enabled() === true && $this->laneProcessManager !== null;
    }

    /**
     * The lane name a subscription belongs to, or null when lanes are off.
     */
    private function laneFor(string $subscriptionId): ?string
    {
        if (!$this->lanesEnabled()) {
            return null;
        }

        return $this->laneRegistry?->laneFor($subscriptionId)?->name;
    }

    private function startStreamIfNotRunning(AllStreamSubscription $stream): void
    {
        if ($this->runningProcesses->isActive($stream->subscriptionId)) {
            return;
        }

        $processId = Ulid::generate();
        try {
            $this->runningProcesses->start(
                $stream->subscriptionId,
                $processId,
                (new DateTime('now'))->add($stream->streamOptions->processTimeoutInSeconds !== null ? new DateInterval("PT{$stream->streamOptions->processTimeoutInSeconds}S") : $this->defaultProcessTimeout),
            );
        } catch (StartProcessException $exception) {
            // process already running, stop execution
            return;
        }

        if (!$this->runSync->isRunSync($stream->messageConsumer)) {
            AllStreamPollSubscriptionJob::dispatch($stream->subscriptionId, $processId)->onQueue($stream->streamOptions->queue);
            return;
        }

        $subscription = $this->allStreamSubscriptionRegistry->get($stream->subscriptionId);
        try {
            $subscription->poll();
        } finally {
            $this->runningProcesses->stop($processId);
        }
    }
}
