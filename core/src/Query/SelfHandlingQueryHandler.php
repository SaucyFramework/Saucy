<?php

namespace Saucy\Core\Query;

final readonly class SelfHandlingQueryHandler implements Middleware
{
    public function __construct(
    ) {}

    public function run(object $message, callable $next): mixed
    {
        if(method_exists($message, 'query')) {
            return app()->call([$message, 'query']);
        }
        return $next($message);
    }
}
