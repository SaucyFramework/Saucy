<?php

declare(strict_types=1);

namespace Workbench\Tests\Lanes\Fixtures;

final readonly class TestEvent
{
    public function __construct(public string $type) {}

    /**
     * @return array<string, string>
     */
    public function toPayload(): array
    {
        return ['type' => $this->type];
    }
}
