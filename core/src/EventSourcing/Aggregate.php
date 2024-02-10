<?php

namespace Saucy\Core\EventSourcing;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
final readonly class Aggregate
{
    public function __construct(
        public string $aggregateIdClass,
        public ?string $name = null,
    ) {}
}
