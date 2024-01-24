<?php

namespace Saucy\Core\Query;

interface Middleware
{
    public function run(object $message, callable $next);
}
