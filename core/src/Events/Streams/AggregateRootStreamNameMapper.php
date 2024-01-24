<?php

namespace Saucy\Core\Events\Streams;

use EventSauce\EventSourcing\AggregateRootId;

final readonly class AggregateRootStreamNameMapper implements StreamNameMapper
{
    public function getStreamNameFor(string $aggregateRootType, AggregateRootId $aggregateRootId): StreamName
    {
        return AggregateStreamName::forAggregateWithId($aggregateRootType, $aggregateRootId);
    }
}
