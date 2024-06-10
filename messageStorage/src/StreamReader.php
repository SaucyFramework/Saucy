<?php

namespace Saucy\MessageStorage;

use EventSauce\EventSourcing\UnableToRetrieveMessages;
use Saucy\Core\Events\Streams\StreamName;

interface StreamReader
{
    /**
     * @return \Generator<StoredEvent>
     *
     * @throws UnableToRetrieveMessages
     */
    public function retrieveAllInStreamSinceCheckpoint(StreamName $streamName, int $position): \Generator;

    public function maxStreamPosition(StreamName $streamName): int;
}
