<?php

namespace Saucy\Core\Subscriptions\PoisonMessages;

enum FailureMode: string
{
    case Halt = 'halt';
    case PauseStream = 'pause_stream';
    case SkipMessage = 'skip_message';
}
