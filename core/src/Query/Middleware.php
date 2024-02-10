<?php

namespace Saucy\Core\Query;

interface Middleware
{
    /**
     * @template T
     * @param Query<T> $message
     * @return T
     */
    public function run(Query $message, callable $next);
}
