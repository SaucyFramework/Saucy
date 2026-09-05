<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes\Fixtures;

use Generator;
use Saucy\MessageStorage\AllStreamQuery;
use Saucy\MessageStorage\AllStreamReader;

/**
 * Fires a one-shot callback immediately before a page is read, so a test can place another
 * process's action between the lane's coordinator acknowledgement and its paginate().
 */
final class HookedAllStreamReader implements AllStreamReader
{
    /** @var (\Closure(): void)|null */
    public ?\Closure $beforePaginate = null;

    public function __construct(private readonly AllStreamReader $inner) {}

    /**
     * @return Generator<int, \Saucy\MessageStorage\StoredEvent>
     */
    public function paginate(AllStreamQuery $streamQuery): Generator
    {
        if ($this->beforePaginate !== null) {
            $hook = $this->beforePaginate;
            $this->beforePaginate = null;
            $hook();
        }

        yield from $this->inner->paginate($streamQuery);
    }

    public function maxEventId(): int
    {
        return $this->inner->maxEventId();
    }
}
