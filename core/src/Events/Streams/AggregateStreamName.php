<?php

namespace Saucy\Core\Events\Streams;

use EventSauce\EventSourcing\AggregateRootId;

final readonly class AggregateStreamName implements AggregateStreamNameInterface
{
    private const DELIMITER = '###';

    public function __construct(
        public string $streamName,
    ) {
    }

    public static function forAggregateWithId(string $aggregateRootType, AggregateRootId $aggregateRootId): AggregateStreamName
    {
        return new self($aggregateRootType . self::DELIMITER . $aggregateRootId->toString());
    }

    public function toString(): string
    {
        return $this->streamName;
    }

    public static function fromString(string $string): StreamName
    {
        return new self($string);
    }
}
