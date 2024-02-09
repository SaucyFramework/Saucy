<?php

namespace Saucy\Core\Subscriptions\MessageConsumption;

interface MessageConsumer
{
    public function handle(MessageConsumeContext $context): void;
}
