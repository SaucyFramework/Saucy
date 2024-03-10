<?php

namespace Saucy\Core\Subscriptions\Infra;

use DateInterval;
use DateTime;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamName;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionProcessManager;
use Saucy\Core\Subscriptions\StreamSubscription\SyncStreamSubscriptionRegistry;
use Saucy\MessageStorage\Hooks\AfterPersistHook;
use Symfony\Component\Uid\Ulid;

final readonly class PlaySynchronousProjectorsAfterPersist implements AfterPersistHook
{
    public function __construct(
        private SyncStreamSubscriptionRegistry $streamSubscriptionRegistry,
        private TypeMap $typeMap,
        private RunningProcesses $runningProcesses,
    ) {}

    public function trigger(StreamName $streamName, StreamEvent ...$streamEvents): void
    {
        $eventTypes = array_map(fn(StreamEvent $streamEvent) => $this->typeMap->instanceToType($streamEvent->payload), $streamEvents);

        if(!$streamName instanceof AggregateStreamName) {
            return;
        }

        if($eventTypes === null) {
            return;
        }

        foreach ($this->streamSubscriptionRegistry->getStreamsForAggregateType($streamName->aggregateRootType) as $stream) {
            if($stream->streamOptions->eventTypes === null) {
                continue;
            }

            if(count(array_intersect($stream->streamOptions->eventTypes, $eventTypes)) === 0) {
                continue;
            }

            $processId = Ulid::generate();
            $subscriptionId = $stream->getId($streamName);
            try {
                $this->runningProcesses->start($subscriptionId, $processId, (new DateTime('now'))->add(new DateInterval("PT10S")));
            } catch (StartProcessException $exception) {
                // process already running, stop execution
                return;
            }
            $handledEvents = $stream->poll($streamName);
            while ($handledEvents > 0) {
                $handledEvents = $stream->poll($streamName);
            }
            $this->runningProcesses->stop($processId);
        }
    }
}
