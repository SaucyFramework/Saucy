<?php

namespace Saucy\Core\Subscriptions\PoisonMessages;

enum PoisonMessageStatus: string
{
    case Poisoned = 'poisoned';
    case Resolved = 'resolved';
    case Skipped = 'skipped';
}
