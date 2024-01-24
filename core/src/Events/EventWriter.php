<?php

namespace Saucy\Core\Events;

use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamName;

interface EventWriter
{
    public function appendEvents(StreamName $streamName, ExpectedVersion $expectedVersion, StreamEvent ...$events): void;
}
