<?php

declare(strict_types=1);

namespace Tests\Unit\Container\Compiler;

use Maduser\Argon\Container\ArgonContainer;
use Maduser\Argon\Container\Compiler\ServiceInvocationGenerator;
use Maduser\Argon\Container\Support\StringHelper;
use Nette\PhpGenerator\ClassType;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Container\Compiler\Stubs\ServiceDependency;
use Tests\Unit\Container\Compiler\Stubs\ServiceWithTypedMethods;

final class ServiceInvocationGeneratorTest extends TestCase
{
    public function testGenerateCreatesInvokerWithNativeCoercionAndServiceReferences(): void
    {
        $container = new ArgonContainer();
        $container->set(ServiceWithTypedMethods::class)
            ->defineInvocation('handle', [
                'id' => '42',
                'ratio' => '3.5',
                'dependency' => '@dependency.service',
                'note' => 'test',
            ]);
        $container->set('dependency.service', static fn() => new ServiceDependency());

        $generator = new ServiceInvocationGenerator($container);
        $class = new ClassType('CompiledContainer');

        $generator->generate($class);

        $methodName = 'invoke_'
            . StringHelper::sanitizeIdentifier(ServiceWithTypedMethods::class)
            . '__handle';

        self::assertTrue($class->hasMethod($methodName));
        $method = $class->getMethod($methodName);
        self::assertSame('mixed', $method->getReturnType());

        $parameters = $method->getParameters();
        self::assertArrayHasKey('args', $parameters);
        $argsParameter = $parameters['args'];
        self::assertSame('array', $argsParameter->getType());
        self::assertTrue($argsParameter->hasDefaultValue());
        self::assertSame([], $argsParameter->getDefaultValue());

        $body = $method->getBody();
        $escapedServiceClass = str_replace('\\', '\\\\', ServiceWithTypedMethods::class);
        self::assertStringContainsString("\$controller = \$this->get('{$escapedServiceClass}');", $body);
        self::assertStringContainsString("\$this->get('dependency.service')", $body);
        self::assertStringContainsString(
            "array_key_exists('dependency', \$args) ? \$args['dependency'] : \$this->get('dependency.service')",
            $body
        );
        self::assertStringNotContainsString('(int)', $body);
        self::assertStringNotContainsString('(float)', $body);
        self::assertStringNotContainsString('Reflection', $body);
        self::assertStringContainsString(
            'CompiledCallableInvoker::invoke($controller->handle(...), $mergedArgs)',
            $body
        );
    }

    public function testGenerateSkipsDescriptorsExcludedFromCompilation(): void
    {
        $container = new ArgonContainer();
        $container->set('closure.service', static fn() => new ServiceWithTypedMethods())
            ->skipCompilation()
            ->defineInvocation('handle', ['id' => 1]);

        $generator = new ServiceInvocationGenerator($container);
        $class = new ClassType('CompiledContainer');

        $generator->generate($class);

        $closureInvokerName = 'invoke_'
            . StringHelper::sanitizeIdentifier('closure.service')
            . '__handle';

        self::assertFalse($class->hasMethod($closureInvokerName));
    }

    public function testPositionalDefaultsRetainArrayMergeSemantics(): void
    {
        $container = new ArgonContainer();
        $container->set(ServiceWithTypedMethods::class)->defineInvocation('handle', ['@dependency.service']);
        $class = new ClassType('CompiledContainer');
        (new ServiceInvocationGenerator($container))->generate($class);
        $method = $class->getMethod(StringHelper::invokeServiceMethod(ServiceWithTypedMethods::class, 'handle'));
        self::assertStringContainsString(
            "array_merge([0 => \$this->get('dependency.service')], \$args)",
            $method->getBody()
        );
        self::assertStringNotContainsString('array_key_exists', $method->getBody());
    }

    public function testGenerateOmitsPrimitiveCastsForClosureConcrete(): void
    {
        $container = new ArgonContainer();
        $container->set('closure.service', static fn() => new ServiceWithTypedMethods())
            ->defineInvocation('handle', ['id' => 1]);

        $generator = new ServiceInvocationGenerator($container);
        $class = new ClassType('CompiledContainer');

        $generator->generate($class);

        $method = $class->getMethod('invoke_closure_service__handle');

        self::assertStringNotContainsString('(int)', $method->getBody());
        self::assertStringContainsString(
            'CompiledCallableInvoker::invoke($controller->handle(...)',
            $method->getBody()
        );
    }

    public function testGenerateOmitsPrimitiveCastsForUnknownInvocationMethod(): void
    {
        $container = new ArgonContainer();
        $container->set(ServiceWithTypedMethods::class)
            ->defineInvocation('missing', ['id' => 1]);

        $generator = new ServiceInvocationGenerator($container);
        $class = new ClassType('CompiledContainer');

        $generator->generate($class);

        $methodName = 'invoke_'
            . StringHelper::sanitizeIdentifier(ServiceWithTypedMethods::class)
            . '__missing';

        $method = $class->getMethod($methodName);

        self::assertStringNotContainsString('(int)', $method->getBody());
        self::assertStringContainsString(
            'CompiledCallableInvoker::invoke($controller->missing(...)',
            $method->getBody()
        );
    }
}
