<?php

namespace Saucy\Core\Subscriptions\PoisonMessages;

final readonly class PoisonMessage
{
    public function __construct(
        public ?int $id,
        public string $subscriptionId,
        public int $globalPosition,
        public string $messageId,
        public string $streamName,
        public string $errorMessage,
        public string $stackTrace,
        public int $retryCount,
        public PoisonMessageStatus $status,
        public \DateTimeImmutable $poisonedAt,
        public ?\DateTimeImmutable $resolvedAt = null,
    ) {}
}
