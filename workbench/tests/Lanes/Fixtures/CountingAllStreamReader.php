<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes\Fixtures;

use Generator;
use Saucy\MessageStorage\AllStreamQuery;
use Saucy\MessageStorage\AllStreamReader;

final class CountingAllStreamReader implements AllStreamReader
{
    public int $paginateCalls = 0;

    /** @var array<int, AllStreamQuery> */
    public array $queries = [];

    public function __construct(private readonly AllStreamReader $inner) {}

    /**
     * @return Generator<int, \Saucy\MessageStorage\StoredEvent>
     */
    public function paginate(AllStreamQuery $streamQuery): Generator
    {
        $this->paginateCalls++;
        $this->queries[] = $streamQuery;

        return $this->inner->paginate($streamQuery);
    }

    public function maxEventId(): int
    {
        return $this->inner->maxEventId();
    }
}
