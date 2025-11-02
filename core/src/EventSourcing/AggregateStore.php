<?php

namespace Saucy\Core\EventSourcing;

use EventSauce\EventSourcing\AggregateRoot;
use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\Message;
use Generator;
use Illuminate\Support\Facades\Log;
use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamNameMapper;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\MessageStorage\AllStreamMessageRepository;
use Symfony\Component\Uid\Ulid;

final readonly class AggregateStore
{
    public function __construct(
        private EventStoreRegistry $eventStoreRegistry,
        private StreamNameMapper $streamNameMapper,
        private TypeMap $typeMap,
        private AggregateEventStoreMap $aggregateEventStoreMap,
    ) {}

    /**
     * @param AggregateRoot<AggregateRootId> $aggregateRoot
     */
    public function persist(AggregateRoot $aggregateRoot): void
    {
        $messageRepository = $this->resolveStoreForAggregate(get_class($aggregateRoot));

        $streamName = $this->streamNameMapper->getStreamNameFor(
            $this->typeMap->instanceToType($aggregateRoot),
            $aggregateRoot->aggregateRootId()
        );

        $aggregateRootVersion = $aggregateRoot->aggregateRootVersion();

        $events = $aggregateRoot->releaseEvents();

        $aggregateRootVersion = $aggregateRootVersion - count($events);

        $streamEvents = array_map(
            function (object $event) use (&$aggregateRootVersion) {
                return new StreamEvent(
                    eventId: Ulid::generate(),
                    payload: $event,
                    metadata: [],
                    position: ++$aggregateRootVersion,
                );
            },
            $events
        );

        $messageRepository->persist($streamName, ...$streamEvents);
    }

    /**
     * @template T of AggregateRoot
     * @param class-string<T> $aggregateRootClass
     * @param AggregateRootId $aggregateRootId
     * @return T
     */
    public function retrieve(string $aggregateRootClass, AggregateRootId $aggregateRootId): AggregateRoot
    {
        $messageRepository = $this->resolveStoreForAggregate($aggregateRootClass);

        $streamName = $this->streamNameMapper->getStreamNameFor(
            $this->typeMap->classNameToType($aggregateRootClass),
            $aggregateRootId
        );

        $events = $this->messagesToEvents(
            streamEvents: $messageRepository->retrieveAllInStream($streamName)
        );

        return $aggregateRootClass::reconstituteFromEvents($aggregateRootId, $events);
    }

    /**
     * @param class-string $aggregateRootClass
     */
    private function resolveStoreForAggregate(string $aggregateRootClass): AllStreamMessageRepository
    {
        return $this->eventStoreRegistry->get(
            $this->aggregateEventStoreMap->getEventStoreId($aggregateRootClass)
        );
    }

    /**
     * @param Generator<StreamEvent> $streamEvents
     * @return Generator<int, object, void, int>
     */
    private function messagesToEvents(Generator $streamEvents): Generator
    {
        $lastPosition = 0;
        /** @var StreamEvent $streamEvent */
        foreach ($streamEvents as $streamEvent) {
            yield $streamEvent->payload;
            $lastPosition = $streamEvent->position;
        }
        return $lastPosition;
    }

}
