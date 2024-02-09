<?php

namespace Saucy\Core\Subscriptions\AllStream;

final readonly class AllStreamSubscriptionRegistry
{
    /**
        * @var array<string, AllStreamSubscription>
     */
    public array $streams;

    public function __construct(
        AllStreamSubscription...$streams
    )
    {
        $streamsMap = [];
        foreach ($streams as $stream) {
            $streamsMap[$stream->subscriptionId] = $stream;
        }

        $this->streams = $streamsMap;
    }

    public function get(string $subscriptionId): AllStreamSubscription
    {
        return $this->streams[$subscriptionId] ?? throw new \Exception("Subscription not found: $subscriptionId");
    }
}
