<?php

namespace Saucy\Core\Events\Streams;

use EventSauce\EventSourcing\AggregateRootId;

readonly class AggregateStreamName implements AggregateStreamNameInterface
{
    private const DELIMITER = '###';

    public function __construct(
        public string $aggregateRootType,
        public string $aggregateRootId,
    ) {}

    public static function forAggregateWithId(string $aggregateRootType, AggregateRootId $aggregateRootId): AggregateStreamName
    {
        return new self($aggregateRootType, $aggregateRootId->toString());
    }

    public function toString(): string
    {
        return $this->aggregateRootType . self::DELIMITER . $this->aggregateRootId;
    }

    public static function fromString(string $string): StreamName
    {
        [$aggregateRootType, $aggregateRootId] = explode(self::DELIMITER, $string);
        return new self($aggregateRootType, $aggregateRootId);
    }

    public function aggregateRootIdAsString(): string
    {
        return $this->aggregateRootId;
    }

    public function type(): string
    {
        return $this->aggregateRootType;
    }
}
