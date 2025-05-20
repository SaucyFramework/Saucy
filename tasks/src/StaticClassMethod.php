<?php

namespace Saucy\Tasks;

use EventSauce\EventSourcing\Serialization\SerializablePayload;

final readonly class StaticClassMethod implements TaskLocation, SerializablePayload
{
    public function __construct(
        public string $className,
        public string $methodName,
    ) {}

    public function toPayload(): array
    {
        return [
            'className' => $this->className,
            'methodName' => $this->methodName,
        ];
    }

    public static function fromPayload(array $payload): static
    {
        return new static(
            className: $payload['className'],
            methodName: $payload['methodName'],
        );
    }
}
