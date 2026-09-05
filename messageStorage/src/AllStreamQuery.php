<?php

namespace Saucy\MessageStorage;

final readonly class AllStreamQuery
{
    /**
     * @param int $fromPosition exclusive lower bound
     * @param int $limit
     * @param array<string>|null $eventTypes
     * @param int|null $upToPosition inclusive upper bound; null means "no upper bound"
     */
    public function __construct(
        public int $fromPosition,
        public int $limit,
        public ?array $eventTypes = [],
        public ?int $upToPosition = null,
    ) {}
}
