<?php

namespace Saucy\Tasks;

use Psr\Container\ContainerInterface;

final readonly class TaskRunner
{
    public function __construct(
        private ContainerInterface $container,
    )
    {
    }

    public function run(TaskLocation $taskLocation): mixed
    {
        $arguments = func_get_args();
        // Remove the first argument, which is the task location
        array_shift($arguments);

        return match (true) {
            $taskLocation instanceof InvokableClass => $this->container->get($taskLocation->className)(...$arguments),
            $taskLocation instanceof ClassMethod => $this->container->get($taskLocation->className)->{$taskLocation->methodName}(...$arguments),
            $taskLocation instanceof StaticClassMethod => $taskLocation->className::{$taskLocation->methodName}(...$arguments),
            default => throw new \InvalidArgumentException('Invalid task location'),
        };
    }
}
