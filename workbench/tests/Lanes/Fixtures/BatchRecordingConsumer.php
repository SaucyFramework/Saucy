<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes\Fixtures;

use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumerThatHandlesBatches;

final class BatchRecordingConsumer extends RecordingConsumer implements MessageConsumerThatHandlesBatches
{
    /** @var array<int, string> */
    public array $batchCalls = [];

    public function beforeHandlingBatch(): void
    {
        $this->batchCalls[] = 'before';
    }

    public function afterHandlingBatch(): void
    {
        $this->batchCalls[] = 'after';
    }

    public function getBatchSize(): int
    {
        return 500;
    }
}
