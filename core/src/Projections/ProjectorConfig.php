<?php

namespace Saucy\Core\Projections;


final readonly class ProjectorConfig
{
    public function __construct(
        public string $projectorClass,
        public array $handlingEventClasses,
        public ProjectorType $projectorType,
        public ?string $aggregateType = null,
    )
    {
    }
}
