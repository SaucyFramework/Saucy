<?php

namespace Saucy\MessageStorage\Hooks;

use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamName;

final readonly class Hooks implements Hook
{
    /**
     * @var Hook[]
     */
    private array $hooks;

    public function __construct(
        Hook ...$hooks
    ) {
        $this->hooks = $hooks;
    }
    public function trigger(StreamName $streamName, StreamEvent ...$streamEvents): void
    {
        foreach ($this->hooks as $hook) {
            $hook->trigger($streamName, ...$streamEvents);
        }
    }
}
