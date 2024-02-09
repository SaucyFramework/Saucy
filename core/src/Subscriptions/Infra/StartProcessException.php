<?php

namespace Saucy\Core\Subscriptions\Infra;

use Exception;

final class StartProcessException extends Exception
{
    public static function cannotGetLockForProcess(): self
    {
        return new self("could not obtain lock for process");
    }
}
