<?php

namespace Saucy\Core\Query;

use Closure;

final readonly class QueryBus
{

    private Closure $middlewareChain;

    public function __construct(Middleware ...$middleware)
    {
        $this->middlewareChain = $this->createExecutionChain($middleware);
    }

    /**
     * @template T
     * @param Query<T> $query
     * @return T
     */
    public function query(Query $query)
    {
        return ($this->middlewareChain)($query);
    }

    /**
     * @param Middleware[] $middlewareList
     */
    private function createExecutionChain(array $middlewareList): Closure
    {
        $lastCallable = static fn() => null;

        while ($middleware = array_pop($middlewareList)) {
            $lastCallable = static fn(object $message) => $middleware->run($message, $lastCallable);
        }

        return $lastCallable;
    }
}
