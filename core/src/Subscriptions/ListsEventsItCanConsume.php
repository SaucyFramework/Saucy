<?php

namespace Saucy\Core\Subscriptions;

interface ListsEventsItCanConsume
{
    /**
     * @return array<class-string>
     */
    public function getEventClasses(): array;
}
