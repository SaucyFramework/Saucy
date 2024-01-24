<?php

namespace Saucy\Tasks\Tests;


use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;
use Saucy\Tasks\ClassMethod;
use Saucy\Tasks\InvokableClass;
use Saucy\Tasks\TaskRunner;
use Saucy\Tasks\Tests\fixtures\Classes\StubClass;
use Saucy\Tasks\Tests\fixtures\invokable\InvokableWithArguments;
use Saucy\Tasks\Tests\fixtures\invokable\InvokableWithDependency;
use Saucy\Tasks\Tests\fixtures\invokable\InvokableWithoutArguments;
use Saucy\Tasks\Tests\fixtures\invokable\SomeDependency;

final class RunMethodInClassTest extends TestCase
{
    /** @test */
    public function it_can_call_a_method_in_a_class_that_doesnt_require_arguments()
    {
        $stubClass = new StubClass();
        $container = new Container();
        $container->instance(StubClass::class, $stubClass);

        $taskLocation = new ClassMethod(
            className: StubClass::class,
            methodName: 'methodWithoutArguments'
        );

        $taskRunner = new TaskRunner($container);
        $taskRunner->run($taskLocation);

        $this->assertEquals(1, $stubClass->getCalledTimes());
    }

    /** @test */
    public function it_can_pass_arguments_to_the_method()
    {
        $stubClass = new StubClass();
        $container = new Container();
        $container->instance(StubClass::class, $stubClass);

        $taskLocation = new ClassMethod(
            className: StubClass::class,
            methodName: 'methodWithArguments'
        );

        $taskRunner = new TaskRunner($container);
        $taskRunner->run($taskLocation, 'argumentA', 'argumentB');

        $this->assertEquals(1, $stubClass->getCalledTimes());
        $this->assertEquals('argumentA', $stubClass->getArgumentA());
        $this->assertEquals('argumentB', $stubClass->getArgumentB());
    }
}
