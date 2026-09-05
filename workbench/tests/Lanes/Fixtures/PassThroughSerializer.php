<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes\Fixtures;

use Saucy\MessageStorage\Serialization\EventSerializer;
use Saucy\MessageStorage\Serialization\SerializationResult;

final class PassThroughSerializer implements EventSerializer
{
    public function serialize(object $event): SerializationResult
    {
        return new SerializationResult($event::class, '{}');
    }

    public function deserialize(SerializationResult $serializationResult): object
    {
        return new TestEvent($serializationResult->eventType);
    }
}
