<?php

namespace Saucy\Core\EventSourcing\CommandHandling;

use EventSauce\EventSourcing\AggregateRoot;
use EventSauce\EventSourcing\AggregateRootId;
use Saucy\Core\EventSourcing\AggregateStore;

final readonly class EventSourcingCommandHandler
{
    public const AGGREGATE_ROOT_CLASS = 'aggregateRootClass';
    public const AGGREGATE_ROOT_ID_PROPERTY = 'aggregateRootIdProperty';
    public const AGGREGATE_METHOD = 'aggregateMethod';
    public const COMMAND_ARGUMENT_NAME = 'commandArgumentName';

    public function __construct(
        private AggregateStore $eventSourcingRepository
    ) {}

    /**
     * @param object $message
     * @param array<string, mixed> $metaData
     * @return void
     * @throws \Exception
     */
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

        /** @var class-string<AggregateRoot<AggregateRootId>> $aggregateRootClass */
        $aggregateRootClass = $metaData[self::AGGREGATE_ROOT_CLASS];
        $aggregate = $this->eventSourcingRepository->retrieve($aggregateRootClass, $aggregateRootId);

        try {
            if(array_key_exists(self::COMMAND_ARGUMENT_NAME, $metaData)) {
                app()->call([$aggregate, $metaData[self::AGGREGATE_METHOD]], [$metaData[self::COMMAND_ARGUMENT_NAME] => $message]);
            } else {
                $aggregate->{$metaData[self::AGGREGATE_METHOD]}($message);
            }
        } finally {
            $this->eventSourcingRepository->persist($aggregate);
        }
    }

    /**
     * @param array<string, mixed> $metaData
     */
    public function handleStatic(object $message, array $metaData): void
    {
        if (!array_key_exists(self::AGGREGATE_ROOT_CLASS, $metaData)) {
            throw new \Exception('Aggregate root class not found in metadata');
        }

        if (!array_key_exists(self::AGGREGATE_METHOD, $metaData)) {
            throw new \Exception('Aggregate method not found in metadata');
        }

        /** @var class-string<AggregateRoot<AggregateRootId>> $aggregateRootClass */
        $aggregateRootClass = $metaData[self::AGGREGATE_ROOT_CLASS];
        $aggregate = $aggregateRootClass::{$metaData[self::AGGREGATE_METHOD]}($message);
        $this->eventSourcingRepository->persist($aggregate);
    }
}
