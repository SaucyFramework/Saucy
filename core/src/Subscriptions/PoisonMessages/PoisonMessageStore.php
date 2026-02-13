<?php

namespace Saucy\Core\Subscriptions\PoisonMessages;

interface PoisonMessageStore
{
    public function store(PoisonMessage $message): void;

    public function resolve(int $id): void;

    public function skip(int $id): void;

    public function updateAfterFailedRetry(int $id, string $errorMessage, string $stackTrace): void;

    public function get(int $id): PoisonMessage;

    /**
     * @return array<PoisonMessage>
     */
    public function getUnresolved(?string $subscriptionId = null): array;

    /**
     * @return array<PoisonMessage>
     */
    public function getUnresolvedForStream(string $subscriptionId, string $streamName): array;

    public function hasUnresolvedForStream(string $subscriptionId, string $streamName): bool;
}
