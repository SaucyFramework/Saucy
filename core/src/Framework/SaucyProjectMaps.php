<?php

namespace Saucy\Core\Framework;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Saucy\Core\Command\CommandTaskMap;
use Saucy\Core\Projections\ProjectorMap;
use Saucy\Core\Query\QueryMap;
use Saucy\Core\Serialisation\TypeMap;

final readonly class SaucyProjectMaps implements SerializablePayload
{
    public function __construct(
        public TypeMap $typeMap,
        public ProjectorMap $projectorMap,
        public CommandTaskMap $commandTaskMap,
        public QueryMap $queryMap,
    ) {
    }

    public function toPayload(): array
    {
        return [
            'typeMap' => $this->typeMap->toPayload(),
            'projectorMap' => $this->projectorMap->toPayload(),
            'commandTaskMap' => $this->commandTaskMap->toPayload(),
            'queryMap' => $this->queryMap->toPayload(),
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            typeMap: TypeMap::fromPayload($payload['typeMap']),
            projectorMap: ProjectorMap::fromPayload($payload['projectorMap']),
            commandTaskMap: CommandTaskMap::fromPayload($payload['commandTaskMap']),
            queryMap: QueryMap::fromPayload($payload['queryMap']),
        );
    }
}
