<?php

namespace Saucy\MessageStorage;

final readonly class AllStreamQuery
{
    /**
     * @param int $fromPosition
     * @param int $limit
     * @param array<string>|null $eventTypes
     */
    public function __construct(
        public int $fromPosition,
        public int $limit,
        public ?array $eventTypes = [],
    ) {}
}
