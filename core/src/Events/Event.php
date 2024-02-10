<?php

namespace Saucy\Core\Events;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class Event
{
    public function __construct(
        public string $name,
    ) {}
}
