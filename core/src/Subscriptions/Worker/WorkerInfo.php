<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

final readonly class WorkerInfo
{
    public function __construct(
        public string $id,
        public ?string $sha,
        public int $expiresAt,
    ) {}
}
