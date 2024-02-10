<?php

namespace Saucy\Core\EventSourcing;

use EventSauce\EventSourcing\AggregateRoot;
use EventSauce\EventSourcing\AggregateRootId;
use EventSauce\EventSourcing\Message;
use Generator;
use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamNameMapper;
use Saucy\Core\Serialisation\TypeMap;
use Saucy\MessageStorage\AllStreamMessageRepository;
use Symfony\Component\Uid\Ulid;

final readonly class AggregateStore
{
    public function __construct(
        private AllStreamMessageRepository $messageRepository,
        private StreamNameMapper $streamNameMapper,
        private TypeMap $typeMap,
    ) {}

    /**
     * @param AggregateRoot<AggregateRootId> $aggregateRoot
     */
    public function persist(AggregateRoot $aggregateRoot): void
    {
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

        $this->messageRepository->persist($streamName, ...$streamEvents);
    }

    /**
     * @template T of AggregateRoot
     * @param class-string<T> $aggregateRootClass
     * @param AggregateRootId $aggregateRootId
     * @return T
     */
    public function retrieve(string $aggregateRootClass, AggregateRootId $aggregateRootId): AggregateRoot
    {
        $streamName = $this->streamNameMapper->getStreamNameFor(
            $this->typeMap->classNameToType($aggregateRootClass),
            $aggregateRootId
        );

        $events = $this->messagesToEvents(
            streamEvents: $this->messageRepository->retrieveAllInStream($streamName)
        );

        return $aggregateRootClass::reconstituteFromEvents($aggregateRootId, $events);
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
