<?php

namespace Saucy\Core\Subscriptions;

use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;

interface ConsumeFilter
{
    public function handle(MessageConsumeContext $context, callable $next): void;
}
