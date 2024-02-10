<?php

namespace Saucy\Core\Projections;

final readonly class ProjectorMap
{
    /**
     * @var ProjectorConfig[]
     */
    private array $projectorConfigs;

    public function __construct(ProjectorConfig ...$projectorConfig)
    {
        $this->projectorConfigs = $projectorConfig;
    }

    /**
     * @return ProjectorConfig[]
     */
    public function getProjectorConfigs(): array
    {
        return $this->projectorConfigs;
    }
}
