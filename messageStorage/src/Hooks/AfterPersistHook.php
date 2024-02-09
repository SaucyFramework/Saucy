<?php

namespace Saucy\MessageStorage\Hooks;

use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamName;

interface AfterPersistHook
{
    public function trigger(StreamName $streamName, StreamEvent ...$streamEvents): void;
}
