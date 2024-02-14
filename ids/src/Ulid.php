<?php

namespace Saucy\Ids;

use EventSauce\EventSourcing\AggregateRootId;
use Symfony\Component\Uid\Ulid as UlidGenerator;

/** @phpstan-consistent-constructor */
abstract readonly class Ulid implements AggregateRootId
{
    private function __construct(private string $id)
    {
        if(!UlidGenerator::isValid($id)) {
            throw new \InvalidArgumentException('Invalid ulid');
        }
    }
    public static function generate(): static
    {
        return new static(UlidGenerator::generate());
    }

    public function toString(): string
    {
        return $this->id;
    }

    public static function fromString(string $aggregateRootId): static
    {
        return new static($aggregateRootId);
    }
}
