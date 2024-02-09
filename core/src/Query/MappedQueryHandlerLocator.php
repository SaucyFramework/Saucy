<?php

namespace Saucy\Core\Query;

use Saucy\Tasks\TaskLocation;

final readonly class MappedQueryHandlerLocator
{
    /**
     * @param array<class-string, TaskLocation> $queryHandlerMap
     */
    public function __construct(
        private array $queryHandlerMap,
    )
    {
    }

    /**
     * @throws QueryHandlerNotFound
     */
    public function getForQuery(object $message): TaskLocation
    {
        return $this->queryHandlerMap[get_class($message)] ?? throw QueryHandlerNotFound::for(get_class($message));
    }
}
