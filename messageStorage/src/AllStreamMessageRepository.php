<?php

namespace Saucy\MessageStorage;

use EventSauce\EventSourcing\UnableToPersistMessages;
use EventSauce\EventSourcing\UnableToRetrieveMessages;
use Generator;
use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamName;

interface AllStreamMessageRepository
{
    /**
     * @throws UnableToPersistMessages
     */
    public function persist(StreamName $streamName, StreamEvent ...$streamEvents): void;

    /**
     * @return Generator<StreamEvent>
     *
     * @throws UnableToRetrieveMessages
     */
    public function retrieveAllInStream(StreamName $streamName): Generator;
//
//    /**
//     * @return Generator<int, StreamEvent, void, StreamQ>
//     */
    public function paginate(AllStreamQuery $streamQuery): Generator;
}
