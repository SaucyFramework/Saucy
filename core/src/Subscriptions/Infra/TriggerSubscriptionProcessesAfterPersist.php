<?php

namespace Saucy\Core\Subscriptions\Infra;

use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamName;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionProcessManager;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionProcessManager;
use Saucy\MessageStorage\Hooks\AfterPersistHook;

final readonly class TriggerSubscriptionProcessesAfterPersist implements AfterPersistHook
{
    public function __construct(
        private AllStreamSubscriptionProcessManager $allStreamSubscriptionProcessManager,
        private StreamSubscriptionProcessManager $streamSubscriptionProcessManager,
        private TypeMap $typeMap,
    )
    {
    }

    public function trigger(StreamName $streamName, StreamEvent ...$streamEvents): void
    {
        $eventTypes = array_map(fn(StreamEvent $streamEvent) => $this->typeMap->instanceToType($streamEvent->payload), $streamEvents);
        $this->allStreamSubscriptionProcessManager->startProcessesThatRequireEvents($eventTypes);

        if($streamName instanceof AggregateStreamName){
            $this->streamSubscriptionProcessManager->startProcessesForAggregateInstance($streamName, $eventTypes);
        }
    }
}
