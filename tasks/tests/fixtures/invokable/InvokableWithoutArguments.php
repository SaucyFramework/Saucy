<?php

namespace Saucy\Tasks\Tests\fixtures\invokable;

final class InvokableWithoutArguments
{
    private int $calledTimes = 0;

    public function __invoke()
    {
        $this->calledTimes = $this->calledTimes + 1;
    }

    public function getCalledTimes(): int
    {
        return $this->calledTimes;
    }
}
