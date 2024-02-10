<?php

namespace Saucy\Core\Subscriptions\AllStream;

use DateInterval;
use DateTime;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Infra\StartProcessException;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionRegistry;
use Symfony\Component\Uid\Ulid;

final readonly class AllStreamSubscriptionProcessManager
{
    private DateInterval $defaultProcessTimeout;

    public function __construct(
        private AllStreamSubscriptionRegistry $allStreamSubscriptionRegistry,
        private RunningProcesses $runningProcesses,
        ?DateInterval $defaultProcessTimeout = null,
    )
    {
        $this->defaultProcessTimeout = $defaultProcessTimeout ?? new DateInterval('PT5M');
    }


    /**
     * Can be used to trigger all projections based on a cron schedule
     * @return void
     */
    public function startProcesses(): void
    {
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
        // start all streams as processes
        foreach ($this->allStreamSubscriptionRegistry->streams as $stream) {
            if($stream->streamOptions->eventTypes === null){
                continue;
            }
            if(count(array_intersect($stream->streamOptions->eventTypes, $eventTypes)) === 0){
                continue;
            }
            $this->startStreamIfNotRunning($stream);
        }
    }

    private function startStreamIfNotRunning(AllStreamSubscription $stream): void
    {
        if($this->runningProcesses->isActive($stream->subscriptionId)){
            return;
        }

        $processId = Ulid::generate();
        try {
            $this->runningProcesses->start(
                $stream->subscriptionId,
                $processId,
                (new DateTime('now'))->add($stream->streamOptions->processTimeoutInSeconds !== null ? new DateInterval("PT{$stream->streamOptions->processTimeoutInSeconds}S") : $this->defaultProcessTimeout)
            );
        } catch (StartProcessException $exception){
            // process already running, stop execution
            return;
        }

        AllStreamPollSubscriptionJob::dispatch($stream->subscriptionId, $processId)->onQueue($stream->streamOptions->queue);
    }

}
