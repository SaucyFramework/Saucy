<?php

namespace Saucy\Core\Subscriptions\Handlers;

use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;

/**
 * Inspired by EventSauce
 */
interface HandleMethodInflector
{
    /**
     * @return string[]
     */
    public function handleMethods(object $consumer, MessageConsumeContext $context): array;
}
