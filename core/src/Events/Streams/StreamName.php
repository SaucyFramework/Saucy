<?php

namespace Saucy\Core\Events\Streams;

interface StreamName
{
    public function toString(): string;

    public static function fromString(string $string): self;
}
