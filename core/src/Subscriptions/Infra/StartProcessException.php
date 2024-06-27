<?php

namespace Saucy\Core\Subscriptions\Infra;

use Exception;

final class StartProcessException extends Exception
{
    public static function cannotGetLockForProcess(): self
    {
        return new self("could not obtain lock for process");
    }

    public static function subscriptionIsPaused(?string $reason = null): self
    {
        return new self("subscription is paused: " . $reason);
    }
}
