<?php

namespace Saucy\Core\Command;

interface Middleware
{
    public function run(object $message, callable $next): void;
}
