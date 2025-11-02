<?php

namespace Saucy\Core\EventSourcing;

use Exception;

final class EventStoreNotFoundException extends Exception
{
    public static function forStoreId(string $storeId, ?string $context = null): self
    {
        $message = "Event store '{$storeId}' not found";
        if ($context !== null) {
            $message .= " (used by {$context})";
        }
        $message .= '. Please register the event store in your service provider.';
        return new self($message);
    }
}

