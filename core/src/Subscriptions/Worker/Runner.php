<?php

declare(strict_types=1);

namespace Saucy\Core\Subscriptions\Worker;

/**
 * What runs a subscription: queue jobs triggered after each persist, or the long-running worker
 * (`saucy:work`). Both share locks and checkpoints, so a subscription can move either way at any
 * moment.
 */
enum Runner: string
{
    case Queue = 'queue';
    case Worker = 'worker';
}
