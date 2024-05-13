<?php

namespace Saucy\Core\Subscriptions\MessageConsumption;

interface ConsumeFilter
{
    public function handle(MessageConsumeContext $context, callable $next): void;

    public function handles(string $className): bool;
}
