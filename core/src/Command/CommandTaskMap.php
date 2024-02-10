<?php

namespace Saucy\Core\Command;

final readonly class CommandTaskMap
{
    /**
     * @var array<class-string, CommandTask>
     */
    private array $commandTasks;

    public function __construct(
        CommandTask ...$commandTasks,
    ) {
        $map = [];
        foreach ($commandTasks as $commandTask) {
            $map[$commandTask->commandClass] = $commandTask;
        }

        $this->commandTasks = $map;
    }

    public function has(object $command): bool
    {
        return isset($this->commandTasks[get_class($command)]);
    }

    public function get(object $command): CommandTask
    {
        return $this->commandTasks[get_class($command)];
    }
}
