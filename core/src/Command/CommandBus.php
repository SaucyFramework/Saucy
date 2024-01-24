<?php

namespace Saucy\Core\Command;

use Closure;

final readonly class CommandBus
{
    /** @var Closure(object $command):mixed */
    private Closure $middlewareChain;

    public function __construct(Middleware ...$middleware)
    {
        $this->middlewareChain = $this->createExecutionChain($middleware);
    }

    public function handle(object $message): mixed
    {
        return ($this->middlewareChain)($message);
    }

    public function queue(): void
    {

    }

    /**
     * @param Middleware[] $middlewareList
     */
    private function createExecutionChain(array $middlewareList): Closure
    {
        $lastCallable = static fn () => null;

        while ($middleware = array_pop($middlewareList)) {
            $lastCallable = static fn (object $message) => $middleware->run($message, $lastCallable);
        }

        return $lastCallable;
    }
}
