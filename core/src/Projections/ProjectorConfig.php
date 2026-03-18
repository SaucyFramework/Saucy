<?php

namespace Saucy\Core\Projections;

use EventSauce\EventSourcing\Serialization\SerializablePayload;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer;
use Saucy\Core\Subscriptions\PoisonMessages\FailureMode;

final readonly class ProjectorConfig implements SerializablePayload
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
        public ?int $pageSize = null,
        public ?int $commitBatchSize = null,
        public FailureMode $failureMode = FailureMode::Halt,
        public int $startFrom = 0,
        public ?string $migratingFromSubscriptionId = null,
    ) {}

    public function toPayload(): array
    {
        return [
            'projectorClass' => $this->projectorClass,
            'handlingEventClasses' => $this->handlingEventClasses,
            'projectorType' => $this->projectorType->value,
            'aggregateType' => $this->aggregateType,
            'async' => $this->async,
            'pageSize' => $this->pageSize,
            'commitBatchSize' => $this->commitBatchSize,
            'failureMode' => $this->failureMode->value,
            'startFrom' => $this->startFrom,
            'migratingFromSubscriptionId' => $this->migratingFromSubscriptionId,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            projectorClass: $payload['projectorClass'],
            handlingEventClasses: $payload['handlingEventClasses'],
            projectorType: ProjectorType::from($payload['projectorType']),
            aggregateType: $payload['aggregateType'] ?? null,
            async: $payload['async'] ?? true,
            pageSize: $payload['pageSize'] ?? null,
            commitBatchSize: $payload['commitBatchSize'] ?? null,
            failureMode: FailureMode::from($payload['failureMode'] ?? FailureMode::Halt->value),
            startFrom: $payload['startFrom'] ?? 0,
            migratingFromSubscriptionId: $payload['migratingFromSubscriptionId'] ?? null,
        );
    }
}
