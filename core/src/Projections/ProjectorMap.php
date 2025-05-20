<?php

namespace Saucy\Core\Projections;

use EventSauce\EventSourcing\Serialization\SerializablePayload;

final readonly class ProjectorMap implements SerializablePayload
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

    public function toPayload(): array
    {
        return [
            'projectorConfigs' => array_map(
                static fn(ProjectorConfig $projectorConfig) => $projectorConfig->toPayload(),
                $this->projectorConfigs,
            ),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            ...array_map(
                static fn(array $projectorConfig) => ProjectorConfig::fromPayload($projectorConfig),
                $payload['projectorConfigs'],
            ),
        );
    }
}
