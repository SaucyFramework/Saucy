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

    /**
     * Get the max stream_position for events in a stream that have global_position <= the given limit.
     * Used during migration from all-stream to aggregate projectors.
     */
    public function maxStreamPositionAtGlobalPosition(StreamName $streamName, int $globalPosition): int;
}
