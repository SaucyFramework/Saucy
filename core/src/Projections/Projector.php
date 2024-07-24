<?php

namespace Saucy\Core\Projections;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Projector {
    public function __construct(
        public ?int $pageSize = null,
        public ?int $commitBatchSize = null,
    ) {}
}
