<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes\Fixtures;

use Generator;
use Saucy\MessageStorage\AllStreamQuery;
use Saucy\MessageStorage\AllStreamReader;

/**
 * Reproduces the race where an event is committed between the (empty) page read and the idle
 * advance: paginate() yields nothing and then, as a side effect, a new event appears.
 */
final class EventInsertingReader implements AllStreamReader
{
    /** @var \Closure(): void */
    private \Closure $onEmptyPage;

    private bool $fired = false;

    public function __construct(
        private readonly AllStreamReader $inner,
        callable $onEmptyPage,
    ) {
        $this->onEmptyPage = \Closure::fromCallable($onEmptyPage);
    }

    /**
     * @return Generator<int, \Saucy\MessageStorage\StoredEvent>
     */
    public function paginate(AllStreamQuery $streamQuery): Generator
    {
        $yielded = 0;
        foreach ($this->inner->paginate($streamQuery) as $storedEvent) {
            $yielded++;
            yield $storedEvent;
        }

        if ($yielded === 0 && !$this->fired) {
            $this->fired = true;
            ($this->onEmptyPage)();
        }
    }

    public function maxEventId(): int
    {
        return $this->inner->maxEventId();
    }

    public function safeCeiling(\DateTimeInterface $committedBefore): int
    {
        return $this->inner->safeCeiling($committedBefore);
    }
}
