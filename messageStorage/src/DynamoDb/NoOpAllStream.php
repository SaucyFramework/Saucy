<?php

namespace Saucy\MessageStorage\DynamoDb;

use Generator;
use Saucy\MessageStorage\AllStreamQuery;
use Saucy\MessageStorage\AllStreamReader;
use Saucy\MessageStorage\ReadEventData;
use Saucy\MessageStorage\StoredEvent;

final readonly class NoOpAllStream implements AllStreamReader, ReadEventData
{
    public function paginate(AllStreamQuery $streamQuery): Generator
    {
        return new Generator();
    }

    public function maxEventId(): int
    {
        return 0;
    }

    public function getForEventId(string $messageId): StoredEvent
    {
        throw new \RuntimeException('NoOpAllStream does not support getForEventId');
    }
}
