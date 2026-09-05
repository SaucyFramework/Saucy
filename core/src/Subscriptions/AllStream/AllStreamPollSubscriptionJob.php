<?php

namespace Saucy\Core\Subscriptions\AllStream;

use DateTime;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Infra\StartProcessException;
use Saucy\Core\Subscriptions\Lanes\LaneCoordinator;
use Saucy\Core\Subscriptions\Lanes\LaneRegistry;
use Symfony\Component\Uid\Ulid;

final class AllStreamPollSubscriptionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private int $timestampZeroMessagesHandled;

    private ?LaneRegistry $laneRegistry = null;

    private ?LaneCoordinator $laneCoordinator = null;

    public function __construct(
        public string $subscriptionId,
        public string $processId,
    ) {}

    public function handle(
        AllStreamSubscriptionRegistry $subscriptionRegistry,
        RunningProcesses $runningProcesses,
        ?LaneRegistry $laneRegistry = null,
        ?LaneCoordinator $laneCoordinator = null,
    ): void {
        // Both are no-ops while lanes are disabled: laneFor() returns null and the coordinator
        // is never touched, so the legacy path is byte-for-byte unchanged.
        $this->laneRegistry = $laneRegistry;
        $this->laneCoordinator = $laneCoordinator;

        $subscription = $subscriptionRegistry->get($this->subscriptionId);
        try {
            $this->runSubscription($subscription, $runningProcesses);
        } catch (\Throwable $e) {
            $runningProcesses->stop($this->processId);
            throw $e;
        }
    }

    public function displayName(): string
    {
        return "projection: {$this->subscriptionId}";
    }

    /**
     * @return array<int|string, string>
     */
    public function tags(): array
    {
        return [
            'projection',
            'subscription:' . $this->subscriptionId,
            'processId' => $this->processId,
        ];
    }

    private function runSubscription(AllStreamSubscription $subscription, RunningProcesses $runningProcesses): void
    {
        if (!$runningProcesses->isActive($this->subscriptionId, $this->processId)) {
            $runningProcesses->reportStatus($this->processId, 'stopping');
            $runningProcesses->stop($this->processId);
            $this->startNewProcess($subscription, $runningProcesses);
            return;
        }

        $timeLeft = $runningProcesses->timeLeft($this->processId) - 5;

        if ($timeLeft <= 0) {
            $runningProcesses->reportStatus($this->processId, 'stopping');
            $runningProcesses->stop($this->processId);
            $this->startNewProcess($subscription, $runningProcesses);
            return;
        }

        $runningProcesses->reportStatus($this->processId, 'running');

        $messagesHandled = $subscription->poll($timeLeft);

        if ($messagesHandled === 0) {

            if (!isset($this->timestampZeroMessagesHandled)) {
                $this->timestampZeroMessagesHandled = time();
            }

            if (time() - $this->timestampZeroMessagesHandled >= $subscription->streamOptions->keepProcessingWithoutNewMessagesBeforeStopInSeconds) {
                $runningProcesses->reportStatus($this->processId, 'stopping');
                $runningProcesses->stop($this->processId);
                $this->bumpLaneMembership();
                return;
            }

            usleep($subscription->streamOptions->sleepWhenNoNewMessagesBeforeRetryInMicroseconds);
        } else {
            unset($this->timestampZeroMessagesHandled);
        }

        $this->runSubscription($subscription, $runningProcesses);
    }

    /**
     * A standalone catch-up run has finished. Tell the lane so it re-evaluates and takes the
     * member back once its checkpoint is inside the catch-up window again.
     */
    private function bumpLaneMembership(): void
    {
        $lane = $this->laneRegistry?->laneFor($this->subscriptionId);

        if ($lane !== null) {
            $this->laneCoordinator?->bumpMembership($lane->name);
        }
    }

    private function startNewProcess(AllStreamSubscription $subscription, RunningProcesses $runningProcesses): void
    {
        // start new job
        $newProcessId = Ulid::generate();
        try {
            $runningProcesses->start(
                subscriptionId: $this->subscriptionId,
                processId: $newProcessId,
                expiresAt: (new DateTime('now'))->add($subscription->streamOptions->processTimeoutInSeconds !== null ? new \DateInterval("PT{$subscription->streamOptions->processTimeoutInSeconds}S") : new \DateInterval('PT5M')),
            );
        } catch (StartProcessException $exception) {
            // process already running, stop execution
            return;
        }
        self::dispatch($this->subscriptionId, $newProcessId);
    }
}
