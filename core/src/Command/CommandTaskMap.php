<?php

namespace Saucy\Core\Command;

use EventSauce\EventSourcing\Serialization\SerializablePayload;

final readonly class CommandTaskMap implements SerializablePayload
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

    public function toPayload(): array
    {
        return [
            'commandTasks' => array_map(
                static fn(CommandTask $commandTask) => $commandTask->toPayload(),
                $this->commandTasks,
            ),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            ...array_map(
                static fn(array $commandTask) => CommandTask::fromPayload($commandTask),
                $payload['commandTasks'],
            ),
        );
    }
}
