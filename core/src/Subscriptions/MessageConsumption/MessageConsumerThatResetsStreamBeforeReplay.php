<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\MessageConsumption;

use Saucy\Core\Events\Streams\AggregateStreamName;

interface MessageConsumerThatResetsStreamBeforeReplay extends MessageConsumer
{
    /**
     * Reset projected data for a single aggregate stream.
     */
    public function prepareStreamReplay(AggregateStreamName $streamName): void;
}
