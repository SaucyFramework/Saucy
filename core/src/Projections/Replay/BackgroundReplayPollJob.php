<?php

declare(strict_types=1);

namespace Saucy\Core\Projections\Replay;

use DateTime;
use DateInterval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscription;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Infra\StartProcessException;
use Symfony\Component\Uid\Ulid;

final class BackgroundReplayPollJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    private int $timestampZeroMessagesHandled;

    public function __construct(
        public string $originalSubscriptionId,
        public string $processId,
    ) {}

    public function handle(
        AllStreamSubscriptionRegistry $subscriptionRegistry,
        RunningProcesses $runningProcesses,
        BackgroundReplayStore $replayStore,
    ): void {
        $original = $subscriptionRegistry->get($this->originalSubscriptionId);

        if (!$original->messageConsumer instanceof SupportsBackgroundReplay) {
            throw new \RuntimeException("Projector for '{$this->originalSubscriptionId}' does not support background replay");
        }

        $replaySubscription = ReplaySubscriptionFactory::createReplaySubscription(
            $original,
            $original->messageConsumer,
        );

        try {
            $this->runSubscription($replaySubscription, $runningProcesses);
        } catch (\Throwable $e) {
            $runningProcesses->stop($this->processId);
            $replayStore->markFailed($this->originalSubscriptionId, $e->getMessage());
            throw $e;
        }
    }

    public function displayName(): string
    {
        return "background-replay: {$this->originalSubscriptionId}";
    }

    /**
     * @return array<int|string, string>
     */
    public function tags(): array
    {
        return [
            'background-replay',
            'subscription:' . $this->originalSubscriptionId,
            'processId' => $this->processId,
        ];
    }

    private function runSubscription(AllStreamSubscription $subscription, RunningProcesses $runningProcesses): void
    {
        $replaySubscriptionId = ReplaySubscriptionFactory::replaySubscriptionId($this->originalSubscriptionId);

        if (!$runningProcesses->isActive($replaySubscriptionId, $this->processId)) {
            $runningProcesses->stop($this->processId);
            $this->startNewProcess($subscription, $runningProcesses);
            return;
        }

        $timeLeft = $runningProcesses->timeLeft($this->processId) - 5;

        if ($timeLeft <= 0) {
            $runningProcesses->stop($this->processId);
            $this->startNewProcess($subscription, $runningProcesses);
            return;
        }

        $runningProcesses->reportStatus($this->processId, 'replaying');

        $messagesHandled = $subscription->poll($timeLeft);

        if ($messagesHandled === 0) {
            if (!isset($this->timestampZeroMessagesHandled)) {
                $this->timestampZeroMessagesHandled = time();
            }

            if (time() - $this->timestampZeroMessagesHandled >= $subscription->streamOptions->keepProcessingWithoutNewMessagesBeforeStopInSeconds) {
                $runningProcesses->reportStatus($this->processId, 'replay-caught-up');
                $runningProcesses->stop($this->processId);
                return;
            }

            usleep($subscription->streamOptions->sleepWhenNoNewMessagesBeforeRetryInMicroseconds);
        } else {
            unset($this->timestampZeroMessagesHandled);
        }

        $this->runSubscription($subscription, $runningProcesses);
    }

    private function startNewProcess(AllStreamSubscription $subscription, RunningProcesses $runningProcesses): void
    {
        $replaySubscriptionId = ReplaySubscriptionFactory::replaySubscriptionId($this->originalSubscriptionId);
        $newProcessId = Ulid::generate();

        try {
            $runningProcesses->start(
                subscriptionId: $replaySubscriptionId,
                processId: $newProcessId,
                expiresAt: (new DateTime('now'))->add(
                    $subscription->streamOptions->processTimeoutInSeconds !== null
                        ? new DateInterval("PT{$subscription->streamOptions->processTimeoutInSeconds}S")
                        : new DateInterval('PT5M'),
                ),
            );
        } catch (StartProcessException $exception) {
            return;
        }

        self::dispatch($this->originalSubscriptionId, $newProcessId)
            ->onQueue($subscription->streamOptions->queue);
    }
}
