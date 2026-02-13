<?php

namespace Saucy\Core\Subscriptions\PoisonMessages;

use Illuminate\Support\Collection;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\StreamSubscription\SyncStreamSubscriptionRegistry;
use Saucy\MessageStorage\ReadEventData;
use Saucy\MessageStorage\Serialization\EventSerializer;
use Saucy\MessageStorage\Serialization\SerializationResult;
use Saucy\Core\Serialisation\TypeMap;

final readonly class PoisonMessageManager
{
    public function __construct(
        private PoisonMessageStore $poisonMessageStore,
        private AllStreamSubscriptionRegistry $allStreamRegistry,
        private StreamSubscriptionRegistry $streamRegistry,
        private SyncStreamSubscriptionRegistry $syncStreamRegistry,
        private ReadEventData $readEventData,
        private EventSerializer $eventSerializer,
        private TypeMap $typeMap,
    ) {}

    /**
     * @return Collection<int, PoisonMessage>
     */
    public function listUnresolved(?string $subscriptionId = null): Collection
    {
        return new Collection($this->poisonMessageStore->getUnresolved($subscriptionId));
    }

    public function retry(int $poisonMessageId): void
    {
        $poisonMessage = $this->poisonMessageStore->get($poisonMessageId);
        $consumer = $this->resolveConsumer($poisonMessage->subscriptionId);

        $storedEvent = $this->readEventData->getForEventId($poisonMessage->messageId);

        $payload = $this->eventSerializer->deserialize(
            new SerializationResult(
                eventType: $storedEvent->eventType,
                payload: $storedEvent->payloadJson,
            ),
        );

        /** @var array<string, mixed> $metaData */
        $metaData = json_decode($storedEvent->metadataJson, true);

        $context = new MessageConsumeContext(
            eventId: $storedEvent->eventId,
            subscriptionId: $poisonMessage->subscriptionId,
            streamNameType: $storedEvent->streamNameType,
            streamType: $storedEvent->streamType,
            streamNameAsString: $storedEvent->streamName,
            streamName: $this->typeMap->typeToClassName($storedEvent->streamNameType)::fromString($storedEvent->streamName),
            eventClass: get_class($payload),
            eventType: $storedEvent->eventType,
            event: $payload,
            metaData: $metaData,
            streamPosition: $storedEvent->streamPosition,
            globalPosition: $storedEvent->globalPosition,
            occurredAt: $storedEvent->createdAt,
        );

        try {
            $consumer->handle($context);
            $this->poisonMessageStore->resolve($poisonMessageId);
        } catch (\Throwable $e) {
            $this->poisonMessageStore->updateAfterFailedRetry(
                $poisonMessageId,
                $e->getMessage(),
                $e->getTraceAsString(),
            );
            throw $e;
        }
    }

    public function skip(int $poisonMessageId): void
    {
        $this->poisonMessageStore->skip($poisonMessageId);
    }

    private function resolveConsumer(string $subscriptionId): \Saucy\Core\Subscriptions\MessageConsumption\MessageConsumer
    {
        // Try all-stream subscriptions first
        if (isset($this->allStreamRegistry->streams[$subscriptionId])) {
            return $this->allStreamRegistry->streams[$subscriptionId]->messageConsumer;
        }

        // Try async stream subscriptions
        if (isset($this->streamRegistry->streams[$subscriptionId])) {
            return $this->streamRegistry->streams[$subscriptionId]->messageConsumer;
        }

        // Try sync stream subscriptions
        if (isset($this->syncStreamRegistry->streams[$subscriptionId])) {
            return $this->syncStreamRegistry->streams[$subscriptionId]->messageConsumer;
        }

        throw new \RuntimeException("No subscription found for ID: {$subscriptionId}");
    }
}
