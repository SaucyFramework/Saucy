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
use Symfony\Component\Uid\Ulid;

final class AllStreamPollSubscriptionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private int $timestampZeroMessagesHandled;

    public function __construct(
        public string $subscriptionId,
        public string $processId,
    ) {}

    public function handle(
        AllStreamSubscriptionRegistry $subscriptionRegistry,
        RunningProcesses $runningProcesses,
    ): void {
        $subscription = $subscriptionRegistry->get($this->subscriptionId);
        $this->runSubscription($subscription, $runningProcesses);
    }

    private function runSubscription(AllStreamSubscription $subscription, RunningProcesses $runningProcesses): void
    {
        if(!$runningProcesses->isActive($this->subscriptionId, $this->processId)) {
            $runningProcesses->reportStatus($this->processId, 'stopping');
            $runningProcesses->stop($this->processId);
            $this->startNewProcess($subscription, $runningProcesses);
            return;
        }

        $timeLeft = $runningProcesses->timeLeft($this->processId) - 5;

        if($timeLeft < 0) {
            $runningProcesses->reportStatus($this->processId, 'stopping');
            $runningProcesses->stop($this->processId);
            $this->startNewProcess($subscription, $runningProcesses);
            return;
        }

        $runningProcesses->reportStatus($this->processId, 'running');

        $messagesHandled = $subscription->poll($timeLeft);

        if($messagesHandled === 0) {

            if(!isset($this->timestampZeroMessagesHandled)) {
                $this->timestampZeroMessagesHandled = time();
            }

            if(time() - $this->timestampZeroMessagesHandled >= $subscription->streamOptions->keepProcessingWithoutNewMessagesBeforeStopInSeconds) {
                $runningProcesses->reportStatus($this->processId, 'stopping');
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
