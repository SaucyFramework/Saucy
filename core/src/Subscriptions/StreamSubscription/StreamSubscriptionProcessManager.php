<?php

namespace Saucy\Core\Subscriptions\StreamSubscription;

use DateInterval;
use DateTime;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Subscriptions\AllStream\AllStreamPollSubscriptionJob;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscription;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\Infra\RunningProcesses;
use Saucy\Core\Subscriptions\Infra\StartProcessException;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionRegistry;
use Symfony\Component\Uid\Ulid;

final readonly class StreamSubscriptionProcessManager
{
    private DateInterval $defaultProcessTimeout;

    public function __construct(
        private StreamSubscriptionRegistry $streamSubscriptionRegistry,
        private RunningProcesses $runningProcesses,
        ?DateInterval $defaultProcessTimeout = null,
    )
    {
        $this->defaultProcessTimeout = $defaultProcessTimeout ?? new DateInterval('PT5M');
    }

    private function startStreamIfNotRunning(StreamSubscription $stream, AggregateStreamName $aggregateStreamName): void
    {
        $streamId = $stream->getId($aggregateStreamName);
        if($this->runningProcesses->isActive($streamId)){
            return;
        }

        $processId = Ulid::generate();
        try {
            $this->runningProcesses->start(
                $streamId,
                $processId,
                (new DateTime('now'))->add($stream->streamOptions->processTimeoutInSeconds !== null ? new DateInterval("PT{$stream->streamOptions->processTimeoutInSeconds}S") : $this->defaultProcessTimeout)
            );
        } catch (StartProcessException $exception){
            // process already running, stop execution
            return;
        }

        StreamPollSubscriptionJob::dispatch($stream->subscriptionId, $processId, $aggregateStreamName)->onQueue($stream->streamOptions->queue);
    }

    /**
     * @param array<string>|null $eventTypes
     * @throws \Exception
     */
    public function startProcessesForAggregateInstance(AggregateStreamName $streamName, ?array $eventTypes = null): void
    {
        if(!$streamName instanceof AggregateStreamName){
            throw new \Exception("For now only Aggregate root scoped Stream projectors are supported");
        }

        foreach ($this->streamSubscriptionRegistry->getStreamsForAggregateType($streamName->aggregateRootType) as $stream) {
            if($eventTypes === null){
                $this->startStreamIfNotRunning($stream, $streamName);
                continue;
            }

            if($stream->streamOptions->eventTypes === null){
                continue;
            }

            if(count(array_intersect($stream->streamOptions->eventTypes, $eventTypes)) === 0){
                continue;
            }
            $this->startStreamIfNotRunning($stream, $streamName);
        }
    }
}
