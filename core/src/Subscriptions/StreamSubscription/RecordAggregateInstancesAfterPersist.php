<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\StreamSubscription;

use Saucy\Core\Events\Streams\AggregateStreamName;
use Saucy\Core\Events\Streams\StreamEvent;
use Saucy\Core\Events\Streams\StreamName;
use Saucy\MessageStorage\Hooks\AfterPersistHook;

final readonly class RecordAggregateInstancesAfterPersist implements AfterPersistHook
{
    public function __construct(
        private AggregateInstanceRepository $repository,
    ) {}

    public function trigger(StreamName $streamName, StreamEvent ...$streamEvents): void
    {
        if (!$streamName instanceof AggregateStreamName) {
            return;
        }

        $lastEvent = end($streamEvents);
        if ($lastEvent === false) {
            return;
        }

        $this->repository->record(
            $streamName->aggregateRootType,
            $streamName->aggregateRootId,
            $lastEvent->position,
        );
    }
}
