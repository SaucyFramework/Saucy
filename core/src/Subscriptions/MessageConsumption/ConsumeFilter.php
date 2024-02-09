<?php

namespace Saucy\Core\Subscriptions\MessageConsumption;

interface ConsumeFilter
{
    public function handle(MessageConsumeContext $context, callable $next): void;
}
