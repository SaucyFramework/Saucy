<?php

namespace Saucy\Core\Subscriptions\MessageConsumption;

interface FilterThatProcessesInBatches extends ConsumeFilter
{
    public function handlesBatches(): bool;
    public function beforeHandlingBatch(): void;
    public function afterHandlingBatch(): void;

}
