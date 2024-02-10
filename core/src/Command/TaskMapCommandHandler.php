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

    public function run(object $message, callable $next): void
    {
        if($this->commandTaskMap->has($message)) {
            $this->taskRunner->run($this->commandTaskMap->get($message)->taskLocation, $message, $this->commandTaskMap->get($message)->metaData);
        }
        $next($message);
    }
}
