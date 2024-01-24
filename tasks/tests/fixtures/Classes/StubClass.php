<?php

namespace Saucy\Tasks\Tests\fixtures\Classes;

final class StubClass
{
    private int $called = 0;
    private string $argumentA;
    private string $argumentB;

    public function __construct()
    {
    }

    public function methodWithoutArguments(): void
    {
        ++$this->called;
    }

    public function methodWithArguments(string $argumentA, string $argumentB)
    {
        ++$this->called;
        $this->argumentA = $argumentA;
        $this->argumentB = $argumentB;
    }

    public static function staticMethodWithoutArguments(): void
    {
        $self = new self();
        $self->methodWithoutArguments();
    }

    public static function staticMethodWithArguments(string $argumentA, string $argumentB): void
    {
        $self = new self();
        $self->methodWithArguments($argumentA, $argumentB);
    }

    public function getCalledTimes(): int
    {
        return $this->called;
    }

    public function getArgumentA(): string
    {
        return $this->argumentA;
    }

    public function getArgumentB(): string
    {
        return $this->argumentB;
    }
}
