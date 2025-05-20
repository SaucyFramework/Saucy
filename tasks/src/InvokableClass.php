<?php

namespace Saucy\Tasks;

use EventSauce\EventSourcing\Serialization\SerializablePayload;

final readonly class InvokableClass implements TaskLocation, SerializablePayload
{
    public function __construct(
        public string $className,
    ) {}

    public function toPayload(): array
    {
        return [
            'className' => $this->className,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            className: $payload['className'],
        );
    }
}
