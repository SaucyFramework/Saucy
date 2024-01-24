<?php

namespace Saucy\MessageStorage;

final readonly class AllStreamQuery
{
    public function __construct(
        public int $fromPosition,
        public int $limit,
        public ?array $eventTypes = [],
    )
    {
    }
}
