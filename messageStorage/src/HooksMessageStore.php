<?php

namespace Saucy\MessageStorage;

use Generator;
use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamName;
use Saucy\MessageStorage\Hooks\AfterPersistHook;

final readonly class HooksMessageStore implements AllStreamMessageRepository, AllStreamReader
{
    public function __construct(
        private AllStreamMessageRepository&AllStreamReader $inner,
        private ?AfterPersistHook $afterPersistHook = null,
    )
    {
    }

    public function persist(StreamName $streamName, StreamEvent ...$streamEvents): void
    {
        $this->inner->persist($streamName, ...$streamEvents);
        $this->afterPersistHook?->trigger($streamName, ...$streamEvents);
    }

    public function retrieveAllInStream(StreamName $streamName): Generator
    {
        return $this->inner->retrieveAllInStream($streamName);
    }

    public function paginate(AllStreamQuery $streamQuery): Generator
    {
        return $this->inner->paginate($streamQuery);
    }
}
