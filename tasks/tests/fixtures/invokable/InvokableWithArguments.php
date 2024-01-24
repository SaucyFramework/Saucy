<?php

namespace Saucy\Tasks\Tests\fixtures\invokable;

final class InvokableWithArguments
{
    private int $calledTimes = 0;
    private string $argumentA;
    private string $argumentB;

    public function __invoke(string $argumentA, string $argumentB)
    {
        $this->calledTimes = $this->calledTimes + 1;
        $this->argumentA = $argumentA;
        $this->argumentB = $argumentB;
    }

    public function getCalledTimes(): int
    {
        return $this->calledTimes;
    }

    public function getArgumentB(): string
    {
        return $this->argumentB;
    }

    public function getArgumentA(): string
    {
        return $this->argumentA;
    }
}
