<?php

namespace Saucy\MessageStorage;


interface ReadEventData
{
    public function getForEventId(string $messageId): StoredEvent;
}
