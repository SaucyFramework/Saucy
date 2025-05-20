<?php

namespace Saucy\Core\Command;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Saucy\Tasks\ClassMethod;
use Saucy\Tasks\InvokableClass;
use Saucy\Tasks\StaticClassMethod;
use Saucy\Tasks\TaskLocation;

final readonly class CommandTask implements SerializablePayload
{
    /**
     * @param class-string $commandClass
     * @param TaskLocation $taskLocation
     * @param array<string, mixed> $metaData
     */
    public function __construct(
        public string $commandClass,
        public TaskLocation $taskLocation,
        public array $metaData = [],
    ) {}

    public function toPayload(): array
    {
        return [
            'commandClass' => $this->commandClass,
            'taskLocation' => match ($this->taskLocation::class) {
                ClassMethod::class => $this->taskLocation->toPayload(),
                InvokableClass::class => $this->taskLocation->toPayload(),
                StaticClassMethod::class => $this->taskLocation->toPayload(),
            },
            'taskLocationClass' => $this->taskLocation::Class,
            'metaData' => $this->metaData,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            commandClass: $payload['commandClass'],
            taskLocation: $payload['taskLocationClass']::fromPayload($payload['taskLocation']),
            metaData: $payload['metaData'] ?? [],
        );
    }
}
