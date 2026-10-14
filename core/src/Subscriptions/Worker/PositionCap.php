<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

/**
 * The highest global position a worker poll may read; shared by every BoundedAllStreamReader.
 */
final class PositionCap
{
    public function __construct(
        public int $position = 0,
    ) {}
}
