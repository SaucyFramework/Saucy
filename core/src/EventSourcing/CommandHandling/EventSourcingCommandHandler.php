<?php

namespace Saucy\Core\EventSourcing\CommandHandling;

use Saucy\Core\EventSourcing\AggregateStore;

final readonly class EventSourcingCommandHandler
{
    public const AGGREGATE_ROOT_CLASS = 'aggregateRootClass';
    public const AGGREGATE_ROOT_ID_PROPERTY = 'aggregateRootIdProperty';
    const AGGREGATE_METHOD = 'aggregateMethod';

    public function __construct(
        private AggregateStore $eventSourcingRepository
    )
    {
    }

    public function handle(object $message, array $metaData): void
    {
        if (!array_key_exists(self::AGGREGATE_ROOT_CLASS, $metaData)) {
            throw new \Exception('Aggregate root class not found in metadata');
        }

        if (!array_key_exists(self::AGGREGATE_ROOT_ID_PROPERTY, $metaData)) {
            throw new \Exception('Aggregate root id property not found in metadata');
        }

        if (!array_key_exists(self::AGGREGATE_METHOD, $metaData)) {
            throw new \Exception('Aggregate method not found in metadata');
        }

        $aggregateRootId = $message->{$metaData[self::AGGREGATE_ROOT_ID_PROPERTY]};

        $aggregate = $this->eventSourcingRepository->retrieve($metaData[self::AGGREGATE_ROOT_CLASS], $aggregateRootId);
        $aggregate->{$metaData[self::AGGREGATE_METHOD]}($message);
        $this->eventSourcingRepository->persist($aggregate);
    }
}
