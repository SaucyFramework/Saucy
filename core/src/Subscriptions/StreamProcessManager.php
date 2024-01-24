<?php

namespace Saucy\Core\Subscriptions;

interface StreamProcessManager
{
    public function start(string $subscriptionId, string $processId): void;

    public function release(string $processId): void;
}
