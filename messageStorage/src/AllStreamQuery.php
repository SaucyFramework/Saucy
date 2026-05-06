<?php

namespace Saucy\MessageStorage;

final readonly class AllStreamQuery
{
    /**
     * @param int $fromPosition
     * @param int $limit
     */
    public function __construct(
        public int $fromPosition,
        public int $limit,
    ) {}
}
