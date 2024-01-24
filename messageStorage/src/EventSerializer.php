<?php

namespace Saucy\MessageStorage;

use Saucy\Core\Events\Streams\StreamEvent;

interface EventSerializer
{
    public function serialize(StreamEvent $event): StoredEvent;

    public function deserialize(StoredEvent $storedEvent): StreamEvent;
}
