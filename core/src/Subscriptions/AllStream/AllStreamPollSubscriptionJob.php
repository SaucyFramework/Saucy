<?php

namespace Saucy\Core\Subscriptions\AllStream;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;

final class AllStreamPollSubscriptionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    private int $startTimestamp;
    private int $timestampZeroMessagesHandled;

    public function __construct(
        public string $subscriptionId,
        public string $processId,
    ) {

    }

    public function handle(
        AllStreamSubscriptionRegistry $subscriptionRegistry,
        RunningProcesses $runningProcesses,
    ): void
    {
        $this->startTimestamp = time();
        $subscription = $subscriptionRegistry->get($this->subscriptionId);
        $this->runSubscription($subscription, $runningProcesses);
    }

    private function runSubscription(AllStreamSubscription $subscription, RunningProcesses $runningProcesses): void
    {
        if(!$runningProcesses->isActive($this->subscriptionId, $this->processId)){
            $runningProcesses->stop($this->processId);
            return;
        }

        $messagesHandled = $subscription->poll();

        if($messagesHandled === 0){

            if(!isset($this->timestampZeroMessagesHandled)){
                $this->timestampZeroMessagesHandled = time();
            }


            if(time() - $this->timestampZeroMessagesHandled >= $subscription->streamOptions->keepProcessingWithoutNewMessagesBeforeStopInSeconds){
                $runningProcesses->stop($this->processId);
                return;
            }

            usleep($subscription->streamOptions->sleepWhenNoNewMessagesBeforeRetryInMicroseconds);
        }
        else{
            unset($this->timestampZeroMessagesHandled);
        }

        $this->runSubscription($subscription, $runningProcesses);
    }
}
