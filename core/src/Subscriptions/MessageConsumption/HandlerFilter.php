<?php

namespace Saucy\Core\Subscriptions\MessageConsumption;

final readonly class HandlerFilter implements ConsumeFilter
{
    public function __construct(
        private MessageConsumer $messageConsumer
    ) {}

    public function handle(MessageConsumeContext $context, callable $next): void
    {
        $this->messageConsumer->handle($context);
    }
}
