<?php

namespace Saucy\Core\Events\Streams;

use EventSauce\EventSourcing\AggregateRootId;

interface StreamNameMapper
{
    public function getStreamNameFor(string $aggregateRootType, AggregateRootId $aggregateRootId): StreamName;
}
