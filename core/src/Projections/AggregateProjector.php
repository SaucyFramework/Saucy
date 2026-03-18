<?php

namespace Saucy\Core\Projections;

use Attribute;
use Saucy\Core\Subscriptions\PoisonMessages\FailureMode;

#[Attribute(Attribute::TARGET_CLASS)]
class AggregateProjector
{
    public function __construct(
        public string $aggregateClass,
        public bool $async = true,
        public FailureMode $failureMode = FailureMode::Halt,
        public ?string $name = null,
    ) {}
}
