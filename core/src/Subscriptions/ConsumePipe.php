<?php

namespace Saucy\Core\Subscriptions;

use Closure;
use Saucy\Core\Subscriptions\MessageConsumption\ConsumeFilter;
use Saucy\Core\Subscriptions\MessageConsumption\MessageConsumeContext;

final readonly class ConsumePipe
{
    /** @var Closure(object $command):mixed */
    private Closure $filterChain;

    /**
     * @var ConsumeFilter[]
     */
    private array $filters;

    public function __construct(ConsumeFilter ...$filters)
    {
        $this->filterChain = $this->createExecutionChain($filters);
        $this->filters = $filters;
    }

    public function handle(MessageConsumeContext $context): mixed
    {
        return ($this->filterChain)($context);
    }

    public function handles(string $className): bool
    {
        foreach ($this->filters as $filter) {
            if($filter->handles($className)){
                return true;
            }
        }
        return false;
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
