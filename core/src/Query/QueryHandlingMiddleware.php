<?php

namespace Saucy\Core\Query;

use Saucy\Tasks\TaskRunner;

final readonly class QueryHandlingMiddleware implements Middleware
{
    public function __construct(
        private TaskRunner $taskRunner,
        private QueryMap $queryMap,
    )
    {
    }

    public function run(object $message, callable $next): mixed
    {
        return $this->taskRunner->run($this->queryMap->getForQuery($message), $message);
    }
}
