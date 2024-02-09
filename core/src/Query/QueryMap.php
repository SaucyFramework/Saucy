<?php

namespace Saucy\Core\Query;

use Saucy\Tasks\TaskLocation;

final readonly class QueryMap
{
    /**
     * @param array<class-string, TaskLocation> $map
     */
    public function __construct(
        public array $map,
    )
    {
    }

    /**
     * @throws QueryHandlerNotFound
     */
    public function getForQuery(object $message): TaskLocation
    {
        return $this->map[get_class($message)] ?? throw QueryHandlerNotFound::for(get_class($message));
    }
}
