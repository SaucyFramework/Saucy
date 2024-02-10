<?php

namespace Saucy\Core\Projections;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class AggregateProjector
{
    public function __construct(
        public string $aggregateClass,
    ) {}
}
