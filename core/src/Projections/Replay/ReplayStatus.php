<?php

declare(strict_types=1);

namespace Saucy\Core\Projections\Replay;

enum ReplayStatus: string
{
    case Running = 'running';
    case Swapping = 'swapping';
    case Completed = 'completed';
    case Failed = 'failed';
}
