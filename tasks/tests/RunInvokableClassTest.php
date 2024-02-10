<?php

namespace Saucy\Tasks\Tests;

use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Saucy\Tasks\InvokableClass;
use Saucy\Tasks\TaskRunner;
use Saucy\Tasks\Tests\fixtures\invokable\InvokableWithArguments;
use Saucy\Tasks\Tests\fixtures\invokable\InvokableWithDependency;
use Saucy\Tasks\Tests\fixtures\invokable\InvokableWithoutArguments;
use Saucy\Tasks\Tests\fixtures\invokable\SomeDependency;

final class RunInvokableClassTest extends TestCase
{
    /** @test */
    public function it_can_call_an_invokable_class_that_doesnt_require_arguments()
    {
        $invokableClass = new InvokableWithoutArguments();
        $container = new Container();
        $container->instance(InvokableWithoutArguments::class, $invokableClass);

        $taskLocation = new InvokableClass(
            className: InvokableWithoutArguments::class
        );

        $taskRunner = new TaskRunner($container);
        $taskRunner->run($taskLocation);

        $this->assertEquals(1, $invokableClass->getCalledTimes());
    }

    /** @test */
    public function it_can_pass_arguments_to_the_invokable_class()
    {
        $invokableClass = new InvokableWithArguments();
        $container = new Container();
        $container->instance(InvokableWithArguments::class, $invokableClass);

        $taskLocation = new InvokableClass(
            className: InvokableWithArguments::class
        );

        $taskRunner = new TaskRunner($container);
        $taskRunner->run($taskLocation, 'argument1', 'argument2');

        $this->assertEquals(1, $invokableClass->getCalledTimes());
        $this->assertEquals('argument1', $invokableClass->getArgumentA());
        $this->assertEquals('argument2', $invokableClass->getArgumentB());
    }
}
