<?php

namespace Saucy\Core\Subscriptions\StreamSubscription;

final readonly class StreamSubscriptionRegistry
{
    /**
        * @var array<string, StreamSubscription>
     */
    public array $streams;

    public function __construct(
        StreamSubscription...$streams
    )
    {
        $streamsMap = [];
        foreach ($streams as $stream) {
            $streamsMap[$stream->subscriptionId] = $stream;
        }

        $this->streams = $streamsMap;
    }

    public function get(string $subscriptionId): StreamSubscription
    {
        return $this->streams[$subscriptionId] ?? throw new \Exception("Subscription not found: $subscriptionId");
    }

    /**
     * @return array<StreamSubscription>
     */
    public function getStreamsForAggregateType(string $aggregateType): array
    {
        return array_filter($this->streams, fn(StreamSubscription $streamSubscription) => $streamSubscription->aggregateType === $aggregateType);
    }
}
