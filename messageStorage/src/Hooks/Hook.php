<?php

namespace Saucy\MessageStorage\Hooks;

use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamName;

interface Hook
{
    public function trigger(StreamName $streamName, StreamEvent ...$streamEvents): void;
}
