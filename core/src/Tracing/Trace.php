<?php

namespace Saucy\Core\Tracing;

final readonly class Trace
{
    public function __construct(
        public string $type,
        public string|int|array $value,
    )
    {
    }
}
