<?php

namespace Saucy\Core\Tracing;

use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamName;
use Saucy\MessageStorage\Hooks\AfterPersistHook;

final readonly class TracePersistedEventsHook implements AfterPersistHook
{
    public function __construct(
    ){
    }

    public function trigger(StreamName $streamName, StreamEvent ...$streamEvents): void
    {
        foreach ($streamEvents as $event){
            resolve(Tracer::class)->trace('persistedEvent', $event->eventId);
        }
    }
}
