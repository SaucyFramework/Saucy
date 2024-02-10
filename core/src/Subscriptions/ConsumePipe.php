<?php

namespace Saucy\Core\Subscriptions;

use Closure;
use Saucy\Core\Subscriptions\MessageConsumption\ConsumeFilter;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;

final readonly class ConsumePipe
{
    /** @var Closure(object $command):mixed */
    private Closure $filterChain;

    public function __construct(ConsumeFilter ...$filters)
    {
        $this->filterChain = $this->createExecutionChain($filters);
    }

    public function handle(MessageConsumeContext $context): mixed
    {
        return ($this->filterChain)($context);
    }

    /**
     * @param ConsumeFilter[] $filterList
     */
    private function createExecutionChain(array $filterList): Closure
    {
        $lastCallable = static fn() => null;

        while ($filter = array_pop($filterList)) {
            $lastCallable = static fn(MessageConsumeContext $context) => $filter->handle($context, $lastCallable);
        }

        return $lastCallable;
    }
}
