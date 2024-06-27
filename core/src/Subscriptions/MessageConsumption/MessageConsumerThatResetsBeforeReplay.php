<?php

namespace Saucy\Core\Subscriptions\MessageConsumption;

interface MessageConsumerThatResetsBeforeReplay extends MessageConsumer
{
    public function prepareReplay(): void;
}
