<?php

namespace Saucy\Tasks;

final readonly class InvokableClass implements TaskLocation
{
    public function __construct(
        public string $className,
    )
    {
    }
}
