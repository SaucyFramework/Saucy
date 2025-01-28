<?php

namespace Saucy\Core\Subscriptions\StreamSubscription;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Events\Streams\StreamName;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscription;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;

final class StreamPollSubscriptionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private int $timestampZeroMessagesHandled;

    public function __construct(
        public string $subscriptionId,
        public string $processId,
        public StreamName $streamName,
    ) {}

    public function displayName(): string
    {
        return "projection: {$this->subscriptionId} - {$this->streamName->toString()}";
    }

    public function tags(): array
    {
        return [
            'projection',
            'subscription:' . $this->subscriptionId,
            'stream:' . $this->streamName->toString(),
            'streamType:' . $this->streamName->type(),
            'processId' => $this->processId,
        ];
    }

    public function handle(
        StreamSubscriptionRegistry $subscriptionRegistry,
        RunningProcesses $runningProcesses,
    ): void {
        $subscription = $subscriptionRegistry->get($this->subscriptionId);
        $this->runSubscription($subscription, $runningProcesses);
    }

    private function runSubscription(StreamSubscription $subscription, RunningProcesses $runningProcesses): void
    {
        $streamId = $subscription->getId($this->streamName);
        if(!$runningProcesses->isActive($streamId, $this->processId)) {
            $runningProcesses->stop($this->processId);
            return;
        }

        $messagesHandled = $subscription->poll($this->streamName);

        if($messagesHandled === 0) {

            if(!isset($this->timestampZeroMessagesHandled)) {
                $this->timestampZeroMessagesHandled = time();
            }


            if(time() - $this->timestampZeroMessagesHandled >= $subscription->streamOptions->keepProcessingWithoutNewMessagesBeforeStopInSeconds) {
                $runningProcesses->stop($this->processId);
                return;
            }

            usleep($subscription->streamOptions->sleepWhenNoNewMessagesBeforeRetryInMicroseconds);
        } else {
            unset($this->timestampZeroMessagesHandled);
        }

        $this->runSubscription($subscription, $runningProcesses);
    }
}
