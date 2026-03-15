<?php

declare(strict_types=1);

namespace Saucy\Core\Projections\Replay;

use DateInterval;
use DateTime;
use EventSauce\BackOff\BackOffRunner;
use EventSauce\BackOff\LinearBackOffStrategy;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionProcessManager;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\Checkpoints\Checkpoint;
use Saucy\Core\Subscriptions\Checkpoints\CheckpointStore;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Symfony\Component\Uid\Ulid;

final readonly class BackgroundReplayManager
{
    public function __construct(
        private AllStreamSubscriptionRegistry $registry,
        private AllStreamSubscriptionProcessManager $processManager,
        private RunningProcesses $runningProcesses,
        private CheckpointStore $checkpointStore,
        private BackgroundReplayStore $replayStore,
    ) {}

    public function startReplay(string $subscriptionId): void
    {
        $original = $this->registry->get($subscriptionId);

        if (!$original->messageConsumer instanceof SupportsBackgroundReplay) {
            throw new \RuntimeException("Projector for '{$subscriptionId}' does not implement SupportsBackgroundReplay");
        }

        $existingStatus = $this->replayStore->getStatus($subscriptionId);
        if ($existingStatus === ReplayStatus::Running || $existingStatus === ReplayStatus::Swapping) {
            throw new \RuntimeException("A background replay is already in progress for '{$subscriptionId}'");
        }

        $original->messageConsumer->setupReplayTables();

        $this->replayStore->start($subscriptionId);

        $replaySubscriptionId = ReplaySubscriptionFactory::replaySubscriptionId($subscriptionId);
        $processId = Ulid::generate();

        $this->runningProcesses->start(
            subscriptionId: $replaySubscriptionId,
            processId: $processId,
            expiresAt: (new DateTime('now'))->add(
                $original->streamOptions->processTimeoutInSeconds !== null
                    ? new DateInterval("PT{$original->streamOptions->processTimeoutInSeconds}S")
                    : new DateInterval('PT5M'),
            ),
        );

        BackgroundReplayPollJob::dispatch($subscriptionId, $processId)
            ->onQueue($original->streamOptions->queue);
    }

    public function swapReplay(string $subscriptionId): void
    {
        $original = $this->registry->get($subscriptionId);

        if (!$original->messageConsumer instanceof SupportsBackgroundReplay) {
            throw new \RuntimeException("Projector for '{$subscriptionId}' does not implement SupportsBackgroundReplay");
        }

        $status = $this->replayStore->getStatus($subscriptionId);
        if ($status !== ReplayStatus::Running) {
            throw new \RuntimeException("No running background replay for '{$subscriptionId}' (status: {$status?->value})");
        }

        $replaySubscriptionId = ReplaySubscriptionFactory::replaySubscriptionId($subscriptionId);

        $this->replayStore->markSwapping($subscriptionId);

        try {
            // 1. Pause main subscription
            $this->runningProcesses->pause($subscriptionId, 'hot swap in progress');

            // 2. Pause the replay subscription (stop new polls)
            $this->runningProcesses->pause($replaySubscriptionId, 'hot swap in progress');

            // 3. Wait for both processes to drain
            $this->waitForProcessToDrain($subscriptionId);
            $this->waitForProcessToDrain($replaySubscriptionId);

            // 4. Atomic table swap
            $original->messageConsumer->swapReplayTables();

            // 5. Set main checkpoint to replay's position
            // The main projector will naturally catch up the remaining gap after resume
            $replayCheckpoint = $this->checkpointStore->get($replaySubscriptionId);
            $this->checkpointStore->store(new Checkpoint($subscriptionId, $replayCheckpoint->position));

            // 6. Clean up
            $this->checkpointStore->delete($replaySubscriptionId);
            $original->messageConsumer->cleanupAfterSwap();
            $this->replayStore->remove($subscriptionId);
        } catch (\Throwable $e) {
            $this->replayStore->markFailed($subscriptionId, $e->getMessage());
            throw $e;
        } finally {
            // 7. Resume main subscription and trigger it to process any remaining gap
            if ($this->runningProcesses->isPaused($subscriptionId)) {
                $this->runningProcesses->resume($subscriptionId);
            }
            $this->processManager->startProcess($subscriptionId);
        }
    }

    /**
     * Re-trigger the replay poll job for an existing replay that has caught up and stopped.
     */
    public function triggerReplay(string $subscriptionId): void
    {
        $original = $this->registry->get($subscriptionId);

        $status = $this->replayStore->getStatus($subscriptionId);
        if ($status !== ReplayStatus::Running) {
            throw new \RuntimeException("No running background replay for '{$subscriptionId}' (status: {$status?->value})");
        }

        $replaySubscriptionId = ReplaySubscriptionFactory::replaySubscriptionId($subscriptionId);

        if ($this->runningProcesses->isActive($replaySubscriptionId)) {
            return;
        }

        $processId = Ulid::generate();

        $this->runningProcesses->start(
            subscriptionId: $replaySubscriptionId,
            processId: $processId,
            expiresAt: (new DateTime('now'))->add(
                $original->streamOptions->processTimeoutInSeconds !== null
                    ? new DateInterval("PT{$original->streamOptions->processTimeoutInSeconds}S")
                    : new DateInterval('PT5M'),
            ),
        );

        BackgroundReplayPollJob::dispatch($subscriptionId, $processId)
            ->onQueue($original->streamOptions->queue);
    }

    /**
     * Trigger all running replays that don't have an active process.
     * Intended to be called from a scheduler (e.g. every 10 minutes).
     */
    public function triggerStaleReplays(): void
    {
        foreach ($this->replayStore->getAll() as $subscriptionId => $status) {
            if ($status !== ReplayStatus::Running) {
                continue;
            }

            try {
                $this->triggerReplay($subscriptionId);
            } catch (\Throwable) {
                // skip failures, will retry next cron tick
            }
        }
    }

    public function cancelReplay(string $subscriptionId): void
    {
        $original = $this->registry->get($subscriptionId);

        if (!$original->messageConsumer instanceof SupportsBackgroundReplay) {
            throw new \RuntimeException("Projector for '{$subscriptionId}' does not implement SupportsBackgroundReplay");
        }

        $replaySubscriptionId = ReplaySubscriptionFactory::replaySubscriptionId($subscriptionId);

        // Stop replay process
        $this->runningProcesses->pause($replaySubscriptionId, 'cancelling replay');
        $this->waitForProcessToDrain($replaySubscriptionId);

        // Clean up
        $original->messageConsumer->teardownReplayTables();
        $this->checkpointStore->delete($replaySubscriptionId);
        $this->replayStore->remove($subscriptionId);
    }

    private function waitForProcessToDrain(string $subscriptionId): void
    {
        $runner = new BackOffRunner(
            new LinearBackOffStrategy(500, 100),
            \RuntimeException::class,
        );

        $runner->run(function () use ($subscriptionId) {
            if ($this->runningProcesses->isActive($subscriptionId)) {
                throw new \RuntimeException("Process still active for '{$subscriptionId}'");
            }
        });
    }
}
