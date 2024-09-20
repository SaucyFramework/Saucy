<?php

namespace Saucy\Core\Subscriptions\MessageConsumption;

use Saucy\Core\Events\Streams\StreamName;

readonly class MessageConsumeContext
{
    /**
     * @param array<string, mixed> $metaData
     */
    public function __construct(
        public string $eventId,
        public string $subscriptionId,
        public string $streamNameType,
        public string $streamType,
        public string $streamNameAsString,
        public StreamName $streamName,
        public string $eventClass,
        public string $eventType,
        public object $event,
        public array $metaData,
        public int $streamPosition,
        public int $globalPosition,
        public \DateTimeImmutable $occurredAt,
    ) {}

    public function __serialize(): array
    {
        return [
            'eventId' => $this->eventId,
            'subscriptionId' => $this->subscriptionId,
            'streamNameType' => $this->streamNameType,
            'streamType' => $this->streamType,
            'streamNameAsString' => $this->streamNameAsString,
            'streamName' => $this->streamName,
            'eventClass' => $this->eventClass,
            'eventType' => $this->eventType,
            'eventPayload' => $this->event->toPayload(),
            'metaData' => $this->metaData,
            'streamPosition' => $this->streamPosition,
            'globalPosition' => $this->globalPosition,
            'occurredAt' => $this->occurredAt->format('c'),
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->eventId = $data['eventId'];
        $this->subscriptionId = $data['subscriptionId'];
        $this->streamNameType = $data['streamNameType'];
        $this->streamType = $data['streamType'];
        $this->streamNameAsString = $data['streamNameAsString'];
        $this->streamName = $data['streamName'];
        $this->eventClass = $data['eventClass'];
        $this->eventType = $data['eventType'];
        $this->metaData = $data['metaData'];
        $this->streamPosition = $data['streamPosition'];
        $this->globalPosition = $data['globalPosition'];
        $this->occurredAt = new \DateTimeImmutable($data['occurredAt']);
        $this->event = $this->eventClass::fromPayload($data['eventPayload']);
    }
}
