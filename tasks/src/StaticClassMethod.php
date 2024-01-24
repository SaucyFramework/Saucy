<?php

namespace Saucy\Tasks;

final readonly class StaticClassMethod implements TaskLocation
{
    public function __construct(
        public string $className,
        public string $methodName,
    )
    {
    }
}
