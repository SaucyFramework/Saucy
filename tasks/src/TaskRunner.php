<?php

namespace Saucy\Tasks;

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;

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
            $taskLocation instanceof InvokableClass => $this->runInvokable($taskLocation, $arguments),
            $taskLocation instanceof ClassMethod => $this->container->get($taskLocation->className)->{$taskLocation->methodName}(...$arguments),
            $taskLocation instanceof StaticClassMethod => $taskLocation->className::{$taskLocation->methodName}(...$arguments),
            default => throw new \InvalidArgumentException('Invalid task location'),
        };
    }

    /**
     * @param array<mixed> $arguments
     */
    private function runInvokable(InvokableClass $taskLocation, array $arguments): mixed
    {
        $instance = $this->container->get($taskLocation->className);
        // check if instance is callable
        if (!is_callable($instance)) {
            throw new \InvalidArgumentException('Invalid task location');
        }
        return $instance(...$arguments);
    }
}
