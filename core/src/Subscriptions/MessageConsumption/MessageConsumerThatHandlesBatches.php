<?php

namespace Saucy\Core\Subscriptions\MessageConsumption;

interface MessageConsumerThatHandlesBatches
{
    public function beforeHandlingBatch(): void;

    public function afterHandlingBatch(): void;

    public function getBatchSize(): int;
}
