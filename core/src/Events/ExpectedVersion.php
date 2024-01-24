<?php

namespace Saucy\Core\Events;

final readonly class ExpectedVersion
{
    public function __construct(
        public int $expectedVersion
    )
    {
    }
}
