<?php

namespace Saucy\Core\Projections;

use EventSauce\BackOff\BackOffStrategy;
use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Subscriptions\AllStream\AllStreamSubscriptionRegistry;
use Saucy\Core\Subscriptions\StreamSubscription\StreamSubscriptionRegistry;
use Saucy\MessageStorage\ReadEventData;

final readonly class AwaitProjected
{
    public function __construct(
        private AllStreamSubscriptionRegistry $allStreamSubscriptionRegistry,
        private StreamSubscriptionRegistry $streamSubscriptionRegistry,
        private ReadEventData $readEventData,
        private BackOffStrategy $backOffStrategy,
    ) {}

    public function awaitProjectedEvent(string $projectorClass, string $messageId): void
    {
        $eventData = $this->readEventData->getForEventId($messageId);
        foreach ($this->allStreamSubscriptionRegistry->streams as $stream) {
            if($stream->consumePipe->handles($projectorClass)) {
                $tries = 0;
                startAllStream:
                ++$tries;
                $position = $stream->checkpointStore->get($stream->subscriptionId);
                if($position->position < $eventData->globalPosition) {
                    $this->backOffStrategy->backOff($tries, new \Exception("Event not yet projected"));
                    goto startAllStream;
                }
            }
        }

        // do the same for streamSubscriptions
        foreach ($this->streamSubscriptionRegistry->streams as $stream) {
            if($stream->consumePipe->handles($projectorClass)) {
                $tries = 0;

                startStream:
                ++$tries;
                $position = $stream->checkpointStore->get($stream->getId(AggregateStreamName::fromString($eventData->streamName)));
                if($position->position < $eventData->streamPosition) {
                    $this->backOffStrategy->backOff($tries, new \Exception("Event not yet projected"));
                    goto startStream;
                }
            }
        }
    }
}
