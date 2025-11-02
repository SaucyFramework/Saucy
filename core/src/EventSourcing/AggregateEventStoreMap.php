<?php

namespace Saucy\Core\EventSourcing;

use EventSauce\EventSourcing\Serialization\SerializablePayload;

/**
 * Maps aggregate root class names to their event store IDs
 *
 * @template-implements SerializablePayload
 */
final readonly class AggregateEventStoreMap implements SerializablePayload
{
    /**
     * @param array<class-string, ?string> $map Class name => event store ID (null for default)
     */
    public function __construct(
        private array $map = [],
    ) {}

    /**
     * @param class-string $aggregateClass
     */
    public function getEventStoreId(string $aggregateClass): ?string
    {
        return $this->map[$aggregateClass] ?? null;
    }

    public function toPayload(): array
    {
        return [
            'map' => $this->map,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            map: $payload['map'] ?? [],
        );
    }

    /**
     * @return array<class-string, ?string>
     */
    public function toArray(): array
    {
        return $this->map;
    }
}

