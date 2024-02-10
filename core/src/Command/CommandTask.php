<?php

namespace Saucy\Core\Command;

use Saucy\Tasks\TaskLocation;

final readonly class CommandTask
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
}
