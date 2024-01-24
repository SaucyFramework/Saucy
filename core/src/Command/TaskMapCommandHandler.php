<?php

namespace Saucy\Core\Command;

use Saucy\Tasks\TaskRunner;

final readonly class TaskMapCommandHandler implements Middleware
{
    public function __construct(
        private CommandTaskMap $commandTaskMap,
        private TaskRunner $taskRunner,
    )
    {
    }

    public function run(object $message, callable $next)
    {
        if($this->commandTaskMap->has($message)) {
            return $this->taskRunner->run($this->commandTaskMap->get($message)->taskLocation, $message, $this->commandTaskMap->get($message)->metaData);
        }
        return $next($message);
    }
}
