<?php

namespace Saucy\Core\Subscriptions\Infra;

use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamName;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\MessageStorage\Hooks\AfterPersistHook;

final readonly class DeferredTriggerSubscriptionProcessesAfterPersist implements AfterPersistHook
{
    public function __construct(
        private TypeMap $typeMap,
        private ?string $queue = null,
    ) {}

    public function trigger(StreamName $streamName, StreamEvent ...$streamEvents): void
    {
        $eventTypes = array_map(
            fn(StreamEvent $streamEvent) => $this->typeMap->instanceToType($streamEvent->payload),
            $streamEvents,
        );

        $aggregateRootType = null;
        $aggregateRootId = null;

        if ($streamName instanceof AggregateStreamName) {
            $aggregateRootType = $streamName->aggregateRootType;
            $aggregateRootId = $streamName->aggregateRootId;
        }

        TriggerSubscriptionsJob::dispatch(
            eventTypes: $eventTypes,
            aggregateRootType: $aggregateRootType,
            aggregateRootId: $aggregateRootId,
        )->onQueue($this->queue);
    }
}
