<?php

namespace Saucy\Core\Projections;

use Attribute;
use Saucy\Core\Subscriptions\PoisonMessages\FailureMode;

#[Attribute(Attribute::TARGET_CLASS)]
class Projector
{
    public function __construct(
        public ?int $pageSize = null,
        public ?int $commitBatchSize = null,
        public FailureMode $failureMode = FailureMode::Halt,
        public int $startFrom = 0,
        public ?string $name = null,
    ) {}
}
