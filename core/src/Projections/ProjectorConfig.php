<?php

namespace Saucy\Core\Projections;

use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;

final readonly class ProjectorConfig
{
    /**
     * @param class-string<MessageConsumer> $projectorClass
     * @param array<class-string> $handlingEventClasses
     */
    public function __construct(
        public string $projectorClass,
        public array $handlingEventClasses,
        public ProjectorType $projectorType,
        public ?string $aggregateType = null,
        public bool $async = true,
    ) {}
}
